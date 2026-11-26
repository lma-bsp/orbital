<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AgentPersona;
use App\Models\ChatQueue;
use App\Models\DirectoryEntry;
use App\Models\EmailRoutingRule;
use App\Models\KnowledgeStore;
use App\Models\MessageQueue;
use App\Models\SharedDirectory;
use App\Models\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Which AI provider keys this installation actually needs, and whether
 * they are set.
 *
 * A missing key fails late and quietly: the first email routed to an AI
 * persona retries three times and then sits unanswered, and a voice
 * persona plays the fallback prompt instead of talking. This helper
 * turns that into a line in `orbital:status` and a card on the health
 * dashboard before traffic finds it.
 *
 * Requirements are derived from configuration, not a fixed list, so an
 * install that never uses OpenAI isn't nagged about an OpenAI key and a
 * platform with no AI at all is reported as exactly that.
 *
 * Kept as a plain helper like {@see DefaultCredentials} so the CLI, the
 * dashboard and the job-time guards share one source.
 */
final class AiProviderReadiness
{
    /** Providers that authenticate with a key Orbital can hold. */
    public const PROVIDERS = [
        'anthropic' => 'Anthropic',
        'openai' => 'OpenAI',
        'openrouter' => 'OpenRouter',
        'elevenlabs' => 'ElevenLabs',
        'deepgram' => 'Deepgram',
        'cartesia' => 'Cartesia',
    ];

    public const OK = 'ok';

    public const MISSING = 'missing';

    public const INVALID = 'invalid';

    public const NOT_NEEDED = 'not needed';

    public const LEVEL_OK = 'ok';

    public const LEVEL_WARN = 'warn';

    public const LEVEL_DOWN = 'down';

    /** Cache key holding the latest key report per worker. */
    public const WORKER_KEYS_CACHE_KEY = 'agent_worker:keys';

    /**
     * How long a worker's key report counts. Matches the liveness
     * heartbeat's TTL: a worker that stopped reporting is no evidence a
     * key is set anywhere.
     */
    public const WORKER_REPORT_TTL = 120;

    /**
     * Provider => the reasons it's needed.
     *
     * @return array<string, array<int, string>>
     */
    public static function requirements(): array
    {
        $requirements = [];

        foreach (self::needs() as $provider => $reasons) {
            $requirements[$provider] = array_column($reasons, 'reason');
        }

        return $requirements;
    }

    /**
     * One entry per provider with a key slot.
     *
     * - `status`: ok / missing / invalid (probe only) / not needed
     * - `level`: ok / warn / down. Down when a reason is live inbound
     *   routing that fails on the next message.
     * - `set_in`: where a key was found (`settings`, `worker`).
     * - `reasons`: why it's needed; for a missing key, only the unmet
     *   reasons.
     *
     * @return array<string, array{label: string, status: string, level: string, set_in: array<int, string>, reasons: array<int, string>}>
     */
    public static function report(bool $probe = false): array
    {
        $needs = self::needs();
        $report = [];

        foreach (self::PROVIDERS as $provider => $label) {
            $laravel = self::laravelHas($provider);
            $worker = self::workerHas($provider);
            $setIn = array_values(array_filter([
                $laravel ? 'settings' : null,
                $worker ? 'worker' : null,
            ]));

            $reasons = $needs[$provider] ?? [];

            if ($reasons === []) {
                $report[$provider] = self::row($label, self::NOT_NEEDED, self::LEVEL_OK, $setIn, []);

                continue;
            }

            // A key Laravel holds is also handed to the worker
            // (AgentPersonaController::providerKeys()), so it covers
            // every reason. A key only in the worker's env covers only
            // what the worker does: email and SMS replies run in Laravel.
            $unmet = array_values(array_filter(
                $reasons,
                fn (array $r): bool => ! $laravel && ! ($worker && $r['worker']),
            ));

            if ($unmet !== []) {
                $critical = in_array(true, array_column($unmet, 'critical'), true);
                $report[$provider] = self::row(
                    $label,
                    self::MISSING,
                    $critical ? self::LEVEL_DOWN : self::LEVEL_WARN,
                    $setIn,
                    array_column($unmet, 'reason'),
                );

                continue;
            }

            if ($probe && $laravel && self::probe($provider) === false) {
                $report[$provider] = self::row($label, self::INVALID, self::LEVEL_DOWN, $setIn, array_column($reasons, 'reason'));

                continue;
            }

            $report[$provider] = self::row($label, self::OK, self::LEVEL_OK, $setIn, array_column($reasons, 'reason'));
        }

        return $report;
    }

    /**
     * Rows that need attention (missing or invalid).
     *
     * @param  array<string, array{label: string, status: string, level: string, set_in: array<int, string>, reasons: array<int, string>}>  $report
     * @return array<string, array{label: string, status: string, level: string, set_in: array<int, string>, reasons: array<int, string>}>
     */
    public static function problems(array $report): array
    {
        return array_filter($report, fn (array $row): bool => in_array($row['status'], [self::MISSING, self::INVALID], true));
    }

    /**
     * Whether Laravel has the key, from Platform Settings or the
     * environment (both land in config()).
     */
    public static function laravelHas(string $provider): bool
    {
        return trim((string) config("services.{$provider}.api_key")) !== '';
    }

    /**
     * Whether every worker that reported recently has the key in its
     * env. "Every" because a call can land on any of them; one worker
     * without the key fails the calls it gets.
     */
    public static function workerHas(string $provider): bool
    {
        $reports = self::workerReports();

        if ($reports === []) {
            return false;
        }

        foreach ($reports as $report) {
            if (($report['keys'][$provider] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * Latest fresh key report per worker.
     *
     * @return array<string, array{keys: array<string, bool>, at: string}>
     */
    public static function workerReports(): array
    {
        $reports = Cache::get(self::WORKER_KEYS_CACHE_KEY);

        if (! is_array($reports)) {
            return [];
        }

        $cutoff = now()->subSeconds(self::WORKER_REPORT_TTL);

        return array_filter($reports, function (mixed $report) use ($cutoff): bool {
            if (! is_array($report) || ! isset($report['at'], $report['keys']) || ! is_array($report['keys'])) {
                return false;
            }

            try {
                return Carbon::parse((string) $report['at'])->greaterThanOrEqualTo($cutoff);
            } catch (Throwable) {
                return false;
            }
        });
    }

    /**
     * Store a worker's key report (booleans only, never values).
     *
     * @param  array<string, bool>  $keys
     */
    public static function recordWorkerKeys(string $worker, array $keys): void
    {
        $reports = self::workerReports();

        $reports[$worker] = [
            'keys' => array_intersect_key($keys, self::PROVIDERS),
            'at' => now()->toIso8601String(),
        ];

        Cache::put(self::WORKER_KEYS_CACHE_KEY, $reports, now()->addSeconds(self::WORKER_REPORT_TTL * 2));
    }

    /**
     * A cheap authenticated call against the provider.
     *
     * true: the key works; false: the provider rejected it (401/403);
     * null: not set, or the result says nothing about the key (network
     * error, 5xx, rate limit).
     */
    public static function probe(string $provider): ?bool
    {
        $key = trim((string) config("services.{$provider}.api_key"));

        if ($key === '') {
            return null;
        }

        $request = match ($provider) {
            'anthropic' => [Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01']), 'https://api.anthropic.com/v1/models'],
            'openai' => [Http::withToken($key), 'https://api.openai.com/v1/models'],
            'openrouter' => [Http::withToken($key), 'https://openrouter.ai/api/v1/key'],
            'elevenlabs' => [Http::withHeaders(['xi-api-key' => $key]), 'https://api.elevenlabs.io/v1/user'],
            'deepgram' => [Http::withHeaders(['Authorization' => 'Token '.$key]), 'https://api.deepgram.com/v1/projects'],
            'cartesia' => [Http::withHeaders(['X-API-Key' => $key, 'Cartesia-Version' => '2024-06-10']), 'https://api.cartesia.ai/voices'],
            default => null,
        };

        if ($request === null) {
            return null;
        }

        [$client, $url] = $request;

        try {
            $response = $client->acceptJson()->timeout(5)->get($url);
        } catch (Throwable) {
            return null;
        }

        if ($response->successful()) {
            return true;
        }

        return in_array($response->status(), [401, 403], true) ? false : null;
    }

    /**
     * Provider => reasons, each with whether it is critical (live
     * inbound routing that fails on the next message) and whether the
     * worker's own env can satisfy it.
     *
     * @return array<string, array<int, array{reason: string, critical: bool, worker: bool}>>
     */
    private static function needs(): array
    {
        $needs = [];
        $add = function (string $provider, string $reason, bool $critical = false, bool $worker = false) use (&$needs): void {
            if (! isset(self::PROVIDERS[$provider])) {
                return; // local / ollama / unknown: no key to hold
            }
            $needs[$provider][] = ['reason' => $reason, 'critical' => $critical, 'worker' => $worker];
        };

        // ── Voice personas (the Python worker) ───────────────────────
        $personas = AgentPersona::withoutGlobalScope('team')
            ->where('is_active', true)
            ->get(['id', 'name', 'llm_provider', 'tts_provider', 'stt_provider']);

        $byProvider = [];
        foreach ($personas as $persona) {
            $byProvider[self::workerLlm($persona->llm_provider)]['LLM'][] = $persona->name;
            $byProvider[self::workerTts($persona->tts_provider)]['TTS'][] = $persona->name;
            $byProvider[self::workerStt($persona->stt_provider)]['STT'][] = $persona->name;
        }
        foreach ($byProvider as $provider => $uses) {
            foreach ($uses as $use => $names) {
                $add((string) $provider, "{$use} for ".self::countedNames(count($names), 'active persona', $names), worker: true);
            }
        }

        // ── Email routed straight to an AI persona ───────────────────
        $emailRules = EmailRoutingRule::withoutGlobalScope('team')
            ->where('is_active', true)
            ->where('destination_type', EmailRoutingRule::DESTINATION_AGENT_PERSONA)
            ->count();
        if ($emailRules > 0) {
            $add('anthropic', 'email replies ('.self::counted($emailRules, 'routing rule').' to an AI persona)', critical: true);
        }

        // ── SMS auto-reply ───────────────────────────────────────────
        if (config('messaging.auto_reply_enabled', false)) {
            $smsQueues = MessageQueue::withoutGlobalScope('team')
                ->where('is_active', true)
                ->whereNotNull('overflow_agent_persona_id')
                ->count();
            if ($smsQueues > 0) {
                $add('anthropic', 'text message replies ('.self::counted($smsQueues, 'message queue').' with an AI persona)', critical: true);
            }
        }

        // ── Web chat overflow to AI (ChatService) ────────────────────
        $chatQueues = ChatQueue::withoutGlobalScope('team')
            ->where('is_active', true)
            ->whereNotNull('overflow_agent_persona_id')
            ->count();
        if ($chatQueues > 0) {
            $add('anthropic', 'web chat replies ('.self::counted($chatQueues, 'chat queue').' with an AI persona)');
        }

        // ── Knowledge store embeddings ───────────────────────────────
        $stores = KnowledgeStore::withoutGlobalScope('team')
            ->where('is_active', true)
            ->pluck('embedding_model');
        $storesByProvider = [];
        foreach ($stores as $model) {
            $provider = strtolower(trim(explode(':', (string) $model, 2)[0]));
            $storesByProvider[$provider] = ($storesByProvider[$provider] ?? 0) + 1;
        }
        foreach ($storesByProvider as $provider => $count) {
            $add((string) $provider, 'embeddings for '.self::counted($count, 'knowledge store'));
        }

        // ── Pre-rendered TTS prompts ─────────────────────────────────
        $greetings = Team::query()
            ->where('voicemail_greeting_mode', 'custom_tts')
            ->whereNotNull('voicemail_greeting_text')
            ->where('voicemail_greeting_text', '!=', '')
            ->get(['id', 'voicemail_greeting_voice_provider']);
        $greetingsByProvider = [];
        foreach ($greetings as $team) {
            $provider = (string) ($team->voicemail_greeting_voice_provider ?: 'openai');
            $greetingsByProvider[$provider] = ($greetingsByProvider[$provider] ?? 0) + 1;
        }
        foreach ($greetingsByProvider as $provider => $count) {
            $add($provider, 'voicemail greetings for '.self::counted($count, 'client'));
        }

        if (self::disclosureConfigured()) {
            // DisclosureRenderer only speaks OpenAI.
            $add('openai', 'the recording disclosure prompt');
        }

        if ($personas->isNotEmpty()) {
            $add((string) (config('telephony.ai_fallback.voice_provider') ?: 'openai'), 'the AI fallback prompt callers hear when an agent fails');
        }

        // ── Directory smart ingest ───────────────────────────────────
        // No on/off switch: the action is on every directory page, so a
        // directory existing is what makes it reachable.
        if (SharedDirectory::query()->exists() || DirectoryEntry::query()->withoutGlobalScopes()->exists()) {
            $add('anthropic', 'directory smart ingest');
        }

        return $needs;
    }

    /**
     * The worker's LLM choice for a persona value (agent.py
     * `_create_llm`): unknown and empty fall back to Anthropic.
     */
    public static function workerLlm(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['anthropic', 'openai', 'openrouter', 'local'], true) ? $value : 'anthropic';
    }

    /** agent.py `_create_tts`: only openai is not ElevenLabs. */
    public static function workerTts(?string $value): string
    {
        return strtolower(trim((string) $value)) === 'openai' ? 'openai' : 'elevenlabs';
    }

    /** agent.py `_create_stt`: only deepgram is not ElevenLabs. */
    public static function workerStt(?string $value): string
    {
        return strtolower(trim((string) $value)) === 'deepgram' ? 'deepgram' : 'elevenlabs';
    }

    private static function disclosureConfigured(): bool
    {
        if (trim((string) config('telephony.recording.disclosure_message', '')) !== '') {
            return true;
        }

        foreach (Team::query()->whereNotNull('recording_overrides')->pluck('recording_overrides') as $overrides) {
            $overrides = is_array($overrides) ? $overrides : (json_decode((string) $overrides, true) ?: []);
            if (trim((string) ($overrides['disclosure_message'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $setIn
     * @param  array<int, string>  $reasons
     * @return array{label: string, status: string, level: string, set_in: array<int, string>, reasons: array<int, string>}
     */
    private static function row(string $label, string $status, string $level, array $setIn, array $reasons): array
    {
        return ['label' => $label, 'status' => $status, 'level' => $level, 'set_in' => $setIn, 'reasons' => $reasons];
    }

    private static function counted(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }

    /**
     * @param  array<int, string>  $names
     */
    private static function countedNames(int $count, string $noun, array $names): string
    {
        $shown = array_slice($names, 0, 3);
        $more = $count > 3 ? ', …' : '';

        return self::counted($count, $noun).' ('.implode(', ', $shown).$more.')';
    }
}
