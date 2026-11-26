<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Jobs\RegenerateTelephonyConfig;
use App\Models\AgentPersona;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\RoutingRule;
use App\Models\Team;
use App\Models\User;
use App\Services\Telephony\AsteriskConfigService;
use App\Services\Telephony\PlatformExtensionAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Security review §8: names reach the generated dialplan. A name with a
 * line break, a `;`, a `)` or a `${...}` expression must come out as one
 * inert line in every file that prints it, rendered through the real
 * AsteriskConfigService with a second, ordinary client alongside.
 */
class DialplanInjectionTest extends TestCase
{
    use RefreshDatabase;

    /** Every trick at once: new line, block comment, expression, app call, closing parens. */
    private const PAYLOAD = "Evil\nexten => 666,1,System(touch /tmp/pwned)\r\n;-- \${SHELL(id)} \$[1+1] ) ] [x]";

    private Team $evil;

    private Team $plain;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('asterisk-config');
        Bus::fake([RegenerateTelephonyConfig::class]);

        $this->evil = $this->makeTenant(self::PAYLOAD, 900001);
        $this->plain = $this->makeTenant('Plain Dental', 900002);

        $persona = AgentPersona::withoutGlobalScopes()->create([
            'team_id' => $this->evil->id, 'name' => self::PAYLOAD, 'role' => 'receptionist',
        ]);
        Extension::withoutGlobalScopes()->create([
            'team_id' => $this->evil->id, 'number' => '7001', 'type' => 'ai_agent', 'is_active' => true,
            'label' => self::PAYLOAD,
            'assignable_type' => AgentPersona::class, 'assignable_id' => $persona->id,
        ]);
        Extension::withoutGlobalScopes()->create([
            'team_id' => $this->evil->id, 'number' => '7002', 'type' => 'sip_phone', 'is_active' => true,
            'context' => 'internal', 'label' => self::PAYLOAD,
        ]);
        // A number is not a name, but it is printed in syntactic position.
        Extension::withoutGlobalScopes()->create([
            'team_id' => $this->evil->id, 'number' => "7003,1,System(id)\n", 'type' => 'sip_phone',
            'is_active' => true, 'context' => 'internal',
        ]);
        CallQueue::withoutGlobalScopes()->create([
            'team_id' => $this->evil->id, 'name' => self::PAYLOAD, 'strategy' => 'ringall', 'timeout' => 30,
            'overflow_agent_persona_id' => $persona->id,
        ]);
        foreach (['extension', 'voicemail'] as $i => $type) {
            RoutingRule::withoutGlobalScopes()->create([
                'team_id' => $this->evil->id, 'name' => self::PAYLOAD, 'match_type' => 'did',
                'match_pattern' => $i === 0 ? "+15550001111\nexten => 666,1,System(id)" : '+15550001112',
                'destination_type' => $type, 'destination_id' => 7002, 'priority' => 10 + $i, 'is_active' => true,
            ]);
        }

        Extension::withoutGlobalScopes()->create([
            'team_id' => $this->plain->id, 'number' => '8001', 'type' => 'sip_phone', 'is_active' => true,
            'context' => 'internal', 'label' => "O'Brien & Co front desk",
        ]);
        CallQueue::withoutGlobalScopes()->create([
            'team_id' => $this->plain->id, 'name' => 'Support', 'strategy' => 'ringall', 'timeout' => 30,
        ]);
        RoutingRule::withoutGlobalScopes()->create([
            'team_id' => $this->plain->id, 'name' => 'DID +15550002222', 'match_type' => 'did',
            'match_pattern' => '+15550002222', 'destination_type' => 'voicemail',
            'destination_id' => 8001, 'priority' => 20, 'is_active' => true,
        ]);

        // A staff user's own name is the one non-admin path in (§8):
        // it becomes their softphone extension's label.
        $staff = User::factory()->create(['name' => self::PAYLOAD]);
        app(PlatformExtensionAllocator::class)->ensureWebrtcExtensionFor($staff);
    }

    /** @return array<string, string> */
    private function renderAll(): array
    {
        $svc = app(AsteriskConfigService::class);

        return [
            'client-evil' => $svc->generateDialplanForTenant($this->evil->id),
            'client-plain' => $svc->generateDialplanForTenant($this->plain->id),
            'from-trunk' => $svc->generateFromTrunkDispatcher(),
            'extensions' => $svc->generateInternalContext(),
            'voicemail' => $svc->generateVoicemail(),
        ];
    }

    public function test_hostile_names_render_as_single_inert_lines_in_every_file(): void
    {
        foreach ($this->renderAll() as $file => $output) {
            foreach (explode("\n", $output) as $line) {
                $this->assertStringNotContainsString("\r", $line, "$file: carriage return survived");
                $this->assertDoesNotMatchRegularExpression('/^\s*exten\s*=>\s*666/', $line, "$file: injected extension line");
                $this->assertDoesNotMatchRegularExpression('/^\s*;--|^\s*\[x\]/', $line, "$file: injected comment/context");
                $this->assertStringNotContainsString('System(', $line, "$file: injected application");
                $this->assertStringNotContainsString('SHELL(', $line, "$file: expression survived");
                $this->assertStringNotContainsString('$[1', $line, "$file: expression survived");

                if (! str_contains($line, 'Evil')) {
                    continue;
                }

                // Where the payload appears it is one line of a known shape.
                $this->assertMatchesRegularExpression(
                    '/^(; client \d+ \(Evil[^()\[\];,$]*\) dialplan'
                    .'|; Rule \d+: Evil[^;,$()\[\]]* → tenant_\d+'
                    .'|exten => [0-9a-z-]+,1,NoOp\((AI Agent|Dialing|Queue): Evil[^()\[\];,$]*( -> t\d+_[a-z0-9_]+)?\)'
                    .'|exten => \d+,1,NoOp\(Dialing Evil[^()\[\];,$]* \d+\)'
                    .'| same => n,NoOp\(Overflow to AI agent: Evil[^()\[\];,$]*\)'
                    .'|\d+ => 0000,Evil[^,;=()$]*,,,tz=eastern)$/u',
                    $line,
                    "$file: unexpected shape for a line carrying the payload",
                );
            }
        }
    }

    public function test_the_sanitised_text_keeps_the_readable_part(): void
    {
        $out = $this->renderAll();

        $this->assertStringContainsString(
            'exten => 7002,1,NoOp(Dialing Evil exten 666 1 System touch /tmp/pwned -- SHELL id 1+1 x 7002)',
            $out['client-evil'],
        );
        $this->assertStringContainsString('900001 => 0000,Evil exten 666 1 System touch /tmp/pwned -- SHELL id 1+1 x,,,tz=eastern', $out['voicemail']);
    }

    public function test_hostile_numbers_and_patterns_are_reduced_to_dial_safe_tokens(): void
    {
        $out = $this->renderAll();

        $this->assertStringContainsString('exten => 70031Systemid,1,NoOp(Dialing extension 70031Systemid)', $out['client-evil']);
        $this->assertStringContainsString('exten => +15550001111exten666', $out['from-trunk']);
        $this->assertStringNotContainsString('=> 666', $out['from-trunk']);
    }

    public function test_the_plain_client_renders_as_before(): void
    {
        $out = $this->renderAll();

        // Not HTML-escaped any more: `{{ }}` used to print &#039; and &amp;.
        $this->assertStringContainsString("exten => 8001,1,NoOp(Dialing O'Brien & Co front desk 8001)", $out['client-plain']);
        $this->assertStringContainsString(' same => n,Dial(PJSIP/8001,18,tTkK)', $out['client-plain']);
        $this->assertStringContainsString(' same => n(busy),VoiceMail(8001@default,b)', $out['client-plain']);
        $this->assertStringContainsString('exten => queue-', $out['client-plain']);
        $this->assertStringContainsString('NoOp(Queue: Support -> t'.$this->plain->id.'_support)', $out['client-plain']);
        $this->assertStringContainsString('; Rule ', $out['from-trunk']);
        $this->assertStringContainsString(': DID +15550002222 → tenant_'.$this->plain->id, $out['from-trunk']);
        $this->assertStringContainsString('exten => +15550002222,1,NoOp(Inbound +15550002222 for client '.$this->plain->id, $out['from-trunk']);
        $this->assertStringContainsString('exten => 15550002222,1,Goto(from-trunk,+15550002222,1)', $out['from-trunk']);
        $this->assertStringContainsString('900002 => 0000,Plain Dental,,,tz=eastern', $out['voicemail']);
        $this->assertStringNotContainsString('Evil', $out['client-plain']);
    }

    private function makeTenant(string $name, int $account): Team
    {
        return Team::forceCreate([
            'user_id' => User::factory()->create()->id,
            'name' => $name,
            'personal_team' => false,
            'account_number' => $account,
        ]);
    }
}
