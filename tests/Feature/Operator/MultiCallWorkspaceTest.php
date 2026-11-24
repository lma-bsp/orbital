<?php

declare(strict_types=1);

namespace Tests\Feature\Operator;

use App\Filament\Pages\MultiCallTrial;
use App\Livewire\MultiCallWorkspace;
use App\Models\ClientDid;
use App\Models\Message;
use App\Models\OperatorCall;
use App\Models\Team;
use App\Services\Operator\MultiCallDesk;
use App\Services\Operator\TestCallPlacer;
use App\Services\Telephony\AsteriskAmiService;
use App\Services\Telephony\PlatformExtensionAllocator;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * The multi-call workspace: a tab per call, each with its own client
 * and draft, behind a Platform Settings option, and a trial desk in the
 * admin panel that never writes to a client's inbox.
 */
class MultiCallWorkspaceTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);
    }

    private function client(string $name = 'Lakeside Family Dental'): Team
    {
        return Team::factory()->create(['name' => $name, 'personal_team' => false]);
    }

    public function test_answering_a_call_puts_the_connected_one_on_hold(): void
    {
        $operator = $this->makeUserWithTeamlessRole('operator');
        $desk = app(MultiCallDesk::class);
        $team = $this->client();

        $first = $desk->receive($operator, true, ['team_id' => $team->id]);
        $second = $desk->receive($operator, true, ['team_id' => $team->id]);

        $desk->answer($first);
        $desk->answer($second);

        $this->assertSame(OperatorCall::STATUS_HELD, $first->refresh()->status);
        $this->assertNotNull($first->held_at);
        $this->assertSame(OperatorCall::STATUS_LIVE, $second->refresh()->status);
    }

    public function test_each_tab_keeps_its_own_draft(): void
    {
        $admin = $this->makeUserWithTeamlessRole('super_admin');
        $scenario = app(MultiCallDesk::class)->startTrialScenario($admin, $this->client());
        [$held, $ringing] = [$scenario[0], $scenario[1]];

        $this->actingAs($admin);

        Livewire::test(MultiCallWorkspace::class, ['trial' => true])
            ->call('select', $ringing->id)
            ->set('draft.reason', 'Cracked molar, swelling since this afternoon.')
            ->call('select', $held->id)
            ->assertSet('draft.reason', 'Wants to book an appointment next week. Asked for a call back this afternoon.')
            ->call('select', $ringing->id)
            ->assertSet('draft.reason', 'Cracked molar, swelling since this afternoon.');
    }

    public function test_trial_message_is_never_written_to_the_client(): void
    {
        $admin = $this->makeUserWithTeamlessRole('super_admin');
        $team = $this->client();
        $call = app(MultiCallDesk::class)->simulateTrialCall($admin, $team, 'Maria Delgado', '5550102231', 'ringing');

        $this->actingAs($admin);

        Livewire::test(MultiCallWorkspace::class, ['trial' => true])
            ->call('select', $call->id)
            ->set('draft.reason', 'Needs a call back.')
            ->call('saveMessage')
            ->assertHasNoErrors();

        $this->assertNotNull($call->refresh()->message_saved_at);
        $this->assertNull($call->message_id);
        $this->assertSame(0, Message::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }

    public function test_live_message_is_filed_for_the_calls_client(): void
    {
        $operator = $this->makeUserWithTeamlessRole('operator');
        $team = $this->client();
        $call = app(MultiCallDesk::class)->receive($operator, false, [
            'team_id' => $team->id,
            'caller_name' => 'Tom Becker',
            'caller_number' => '5550103367',
        ]);

        $this->actingAs($operator);

        Livewire::test(MultiCallWorkspace::class)
            ->call('select', $call->id)
            ->call('saveMessage')
            ->assertHasErrors(['draft.reason'])
            ->set('draft.reason', 'New patient, wants a cleaning.')
            ->call('saveMessage')
            ->assertHasNoErrors();

        $message = Message::withoutGlobalScopes()->where('team_id', $team->id)->sole();
        $this->assertSame('Tom Becker', $message->caller_name);
        $this->assertSame($message->id, $call->refresh()->message_id);
    }

    public function test_softphone_call_loads_the_client_from_the_dialled_number(): void
    {
        $operator = $this->makeUserWithTeamlessRole('operator');
        $team = $this->client();
        ClientDid::withoutEvents(fn () => ClientDid::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'number' => '+15550104411',
            'label' => 'Emergency line',
            'is_active' => true,
        ]));

        $this->actingAs($operator);

        $component = Livewire::test(MultiCallWorkspace::class)
            ->call('followSoftphone', ['phase' => 'incoming', 'callId' => 'abc@host', 'from' => '5550102231', 'did' => '5550104411']);

        $call = OperatorCall::where('sip_call_id', 'abc@host')->sole();
        $this->assertSame($team->id, $call->team_id);
        $this->assertSame('Emergency line', $call->line_label);
        $component->assertSet('selectedId', $call->id);

        $component->call('followSoftphone', ['phase' => 'answered', 'callId' => 'abc@host']);
        $this->assertSame(OperatorCall::STATUS_LIVE, $call->refresh()->status);

        $component->call('followSoftphone', ['phase' => 'ended', 'callId' => 'abc@host']);
        $this->assertSame(OperatorCall::STATUS_ENDED, $call->refresh()->status);
        $this->assertNull($call->closed_at, 'the tab stays open so the message can be finished');
    }

    public function test_an_operator_cannot_act_on_another_operators_call(): void
    {
        $owner = $this->makeUserWithTeamlessRole('operator');
        $other = $this->makeUserWithTeamlessRole('operator');
        $call = app(MultiCallDesk::class)->receive($owner, false, ['team_id' => $this->client()->id]);

        $this->actingAs($other);

        Livewire::test(MultiCallWorkspace::class)
            ->call('closeCall', $call->id)
            ->call('select', $call->id)
            ->assertSet('selectedId', null);

        $this->assertNull($call->refresh()->closed_at);
    }

    public function test_workspace_uses_the_multi_call_layout_only_when_switched_on(): void
    {
        $operator = $this->makeUserWithTeamlessRole('operator');

        config(['orbital.operator.multi_call_workspace' => false]);
        $this->actingAs($operator)->get('/operator/workspace')
            ->assertOk()
            ->assertDontSee('Calls on your desk');

        config(['orbital.operator.multi_call_workspace' => true]);
        $this->actingAs($operator)->get('/operator/workspace')
            ->assertOk()
            ->assertSee('Calls on your desk');
    }

    public function test_trial_page_is_super_admin_only(): void
    {
        $this->actingAs($this->makeUserWithTeamlessRole('super_admin'))
            ->get('/admin/multi-call-trial')
            ->assertOk()
            ->assertSee('Trial desk');

        $this->actingAs($this->makeUserWithTeamlessRole('operator'));
        $this->assertFalse(MultiCallTrial::canAccess());
    }

    public function test_trial_page_loads_the_three_call_scenario(): void
    {
        $admin = $this->makeUserWithTeamlessRole('super_admin');
        $team = $this->client();

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(MultiCallTrial::class)
            ->callAction('scenario', data: ['team_id' => $team->id])
            ->assertHasNoActionErrors();

        $calls = OperatorCall::where('user_id', $admin->id)->where('is_trial', true)->get();
        $this->assertCount(3, $calls);
        $this->assertEqualsCanonicalizing(
            [OperatorCall::STATUS_RINGING, OperatorCall::STATUS_QUEUED, OperatorCall::STATUS_HELD],
            $calls->pluck('status')->all(),
        );

        Livewire::test(MultiCallTrial::class)->callAction('clear');
        $this->assertSame(0, OperatorCall::where('user_id', $admin->id)->count());
    }

    /** Stand-in for Asterisk: records originates, reports the softphone as logged in or not. */
    private function fakeAmi(bool $registered = true, bool $accepts = true): object
    {
        $fake = new class($registered, $accepts) extends AsteriskAmiService
        {
            public array $originated = [];

            public function __construct(private bool $registered, private bool $accepts) {}

            public function endpointIsRegistered(string $endpoint): bool
            {
                return $this->registered;
            }

            public function originateToApplication(string $channel, string $application, string $data, string $callerId, array $variables = [], int $timeoutMs = 30000): bool
            {
                $this->originated[] = compact('channel', 'application', 'data', 'callerId', 'variables');

                return $this->accepts;
            }
        };

        $this->app->instance(AsteriskAmiService::class, $fake);

        return $fake;
    }

    public function test_ring_my_softphone_places_a_real_call_that_opens_with_its_client(): void
    {
        $admin = $this->makeUserWithTeamlessRole('super_admin');
        $ext = app(PlatformExtensionAllocator::class)->ensureWebrtcExtensionFor($admin);
        $team = $this->client();
        $ami = $this->fakeAmi();

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(MultiCallTrial::class)
            ->callAction('ring', data: ['team_id' => $team->id, 'caller_name' => 'Maria Delgado', 'caller_number' => '(555) 010-2231'])
            ->assertHasNoActionErrors();

        $this->assertCount(1, $ami->originated);
        $placed = $ami->originated[0];
        $this->assertSame('PJSIP/'.$ext->sip_username, $placed['channel']);
        $this->assertSame('"Maria Delgado" <5550102231>', $placed['callerId']);

        $call = OperatorCall::sole();
        $this->assertFalse($call->is_trial);
        $this->assertSame($team->id, $call->team_id);
        $this->assertSame($call->test_token, $placed['variables']['PJSIP_HEADER(add,X-Orbital-Test-Call)']);

        // The softphone rings in /operator/workspace: the call finds its record.
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $desk = Livewire::test(MultiCallWorkspace::class)
            ->call('followSoftphone', ['phase' => 'incoming', 'callId' => 'xyz@asterisk', 'from' => '5550102231', 'testCall' => $call->test_token])
            ->assertSet('selectedId', $call->id);

        $this->assertSame(1, OperatorCall::count());
        $this->assertSame('xyz@asterisk', $call->refresh()->sip_call_id);

        // A message saved on it is real.
        $desk->call('followSoftphone', ['phase' => 'answered', 'callId' => 'xyz@asterisk'])
            ->set('draft.reason', 'Test: please call back.')
            ->call('saveMessage')
            ->assertHasNoErrors();

        $this->assertSame(1, Message::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }

    public function test_ring_refuses_when_the_softphone_is_not_logged_in(): void
    {
        $admin = $this->makeUserWithTeamlessRole('super_admin');
        app(PlatformExtensionAllocator::class)->ensureWebrtcExtensionFor($admin);
        $ami = $this->fakeAmi(registered: false);

        $this->expectExceptionMessage("isn't logged in");

        try {
            app(TestCallPlacer::class)->place($admin, $this->client(), null, null);
        } finally {
            $this->assertSame([], $ami->originated);
            $this->assertSame(0, OperatorCall::count());
        }
    }

    public function test_a_test_call_that_never_rang_is_marked_missed(): void
    {
        $admin = $this->makeUserWithTeamlessRole('super_admin');
        app(PlatformExtensionAllocator::class)->ensureWebrtcExtensionFor($admin);
        $this->fakeAmi();

        $call = app(TestCallPlacer::class)->place($admin, $this->client(), null, null);
        $this->travel(TestCallPlacer::RING_SECONDS + 30)->seconds();

        app(MultiCallDesk::class)->openCalls($admin, false);

        $this->assertSame(OperatorCall::STATUS_MISSED, $call->refresh()->status);
    }

    public function test_another_operators_token_does_not_claim_a_test_call(): void
    {
        $owner = $this->makeUserWithTeamlessRole('super_admin');
        $other = $this->makeUserWithTeamlessRole('operator');
        app(PlatformExtensionAllocator::class)->ensureWebrtcExtensionFor($owner);
        $this->fakeAmi();
        $call = app(TestCallPlacer::class)->place($owner, $this->client(), null, null);

        app(MultiCallDesk::class)->followSoftphone($other, ['phase' => 'incoming', 'callId' => 'c1', 'testCall' => $call->test_token]);

        $this->assertNull($call->refresh()->sip_call_id);
        $this->assertNull(OperatorCall::where('user_id', $other->id)->sole()->team_id);
    }
}
