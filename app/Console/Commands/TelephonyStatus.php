<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AgentPersona;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\RtpengineNode;
use App\Models\SipTrunk;
use App\Services\Telephony\AsteriskAmiService;
use App\Services\Telephony\LiveKitConfigService;
use App\Services\Telephony\RtpengineService;
use App\Support\AiProviderReadiness;
use App\Support\DefaultCredentials;
use Illuminate\Console\Command;

class TelephonyStatus extends Command
{
    protected $signature = 'orbital:status
        {--strict : Exit non-zero when a needed AI provider key is missing or invalid}
        {--probe : Check each needed AI provider key with a cheap authenticated call}';

    protected $description = 'Check the status of telephony services (Asterisk, LiveKit)';

    public function handle(AsteriskAmiService $ami, LiveKitConfigService $livekit, RtpengineService $rtpengine): int
    {
        $this->info('Checking Orbital telephony services...');
        $this->newLine();

        // Asterisk AMI
        $this->output->write('  Asterisk AMI ... ');
        $channels = $ami->getActiveChannels();
        if ($channels !== []) {
            $this->info('OK ('.count($channels).' active channels)');
        } else {
            // Empty channels could mean connected with no calls, or failed
            $this->warn('Connected (0 active channels)');
        }

        // LiveKit
        $this->output->write('  LiveKit Server ... ');
        if ($livekit->healthCheck()) {
            $this->info('OK');
        } else {
            $this->error('UNREACHABLE');
        }

        // rtpengine — NG ping fan-out across every active node
        $totalActive = RtpengineNode::active()->count();
        if ($totalActive === 0) {
            $this->output->write('  rtpengine ... ');
            $this->warn('No active nodes registered');
        } else {
            $results = $rtpengine->pingAll();
            $up = count(array_filter($results));
            $this->output->write('  rtpengine ... ');
            if ($up === $totalActive) {
                $this->info("OK ({$up}/{$totalActive} responding)");
            } elseif ($up === 0) {
                $this->error("DOWN (0/{$totalActive} responding)");
            } else {
                $this->warn("DEGRADED ({$up}/{$totalActive} responding)");
            }
            foreach ($results as $hostname => $ok) {
                $this->line('    '.($ok ? '✓' : '✗').' '.$hostname);
            }
        }

        // Database counts
        $this->newLine();
        $this->info('  Database Summary:');
        $this->line('    Extensions: '.Extension::withoutGlobalScopes()->count());
        $this->line('    SIP Trunks: '.SipTrunk::withoutGlobalScopes()->count());
        $this->line('    AI Agents:  '.AgentPersona::withoutGlobalScopes()->where('is_active', true)->count());
        $this->line('    Queues:     '.CallQueue::withoutGlobalScopes()->count());

        $this->reportUnrotatedDefaults();

        $aiProblems = $this->reportAiProviders((bool) $this->option('probe'));

        return $this->option('strict') && $aiProblems > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Which AI provider keys this installation's configuration needs,
     * and which of those are missing. The list lives in
     * {@see AiProviderReadiness} so the dashboard shows the same thing.
     *
     * @return int how many needed keys are missing or invalid
     */
    private function reportAiProviders(bool $probe): int
    {
        $report = AiProviderReadiness::report($probe);
        $needed = array_filter($report, fn (array $row): bool => $row['status'] !== AiProviderReadiness::NOT_NEEDED);
        $problems = AiProviderReadiness::problems($report);

        $this->newLine();

        if ($needed === []) {
            $this->info('  AI providers: no AI configured, no keys needed.');

            return 0;
        }

        if ($problems === []) {
            $this->info('  AI providers: every needed key is set ('.implode(', ', array_column($needed, 'label')).').');

            return 0;
        }

        $this->warn('  AI providers: '.count($problems).' needed key(s) not usable:');

        foreach ($problems as $row) {
            $state = match (true) {
                $row['status'] === AiProviderReadiness::INVALID => 'DOWN, key rejected by the provider',
                $row['level'] === AiProviderReadiness::LEVEL_DOWN => 'DOWN, missing',
                default => 'missing',
            };

            $this->line("    ! {$row['label']} ({$state}): needed for ".implode('; ', $row['reasons']));
        }

        $this->line('    Set these in Platform Settings → AI Providers (or the environment).');

        return count($problems);
    }

    /**
     * Warn about credentials still sitting at their shipped development
     * defaults. SECURITY.md points operators here before go-live, so it
     * has to actually check. The list lives in
     * {@see DefaultCredentials}.
     */
    private function reportUnrotatedDefaults(): void
    {
        $unrotated = DefaultCredentials::unrotated();

        $this->newLine();

        if ($unrotated === []) {
            $this->info('  Credentials: no shipped defaults still in use.');

            return;
        }

        $this->warn('  Credentials: '.count($unrotated).' still at their shipped default:');

        foreach ($unrotated as $label) {
            $this->line('    ! '.$label);
        }

        $this->line('    Rotate these before this installation takes real calls. See SECURITY.md.');
    }
}
