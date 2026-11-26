<?php

declare(strict_types=1);

namespace App\Services\Telephony\Realtime;

use Illuminate\Support\Facades\DB;

/**
 * The PJSIP endpoints every install needs, as realtime rows.
 *
 * sorcery.conf reads endpoints from Postgres only, so the static
 * docker/asterisk/pjsip_livekit.conf is never loaded; without these
 * rows Dial(PJSIP/{ext}@livekit) has no endpoint and AI calls fail.
 *
 *  - `livekit`: the LiveKit SIP bridge. Asterisk dials AI calls to it,
 *    and the AI's transfers come back as REFERs on that leg, so its
 *    context is from-livekit. It has no auth, so it is identified by
 *    source IP only (identify_by=ip plus ps_endpoint_id_ips rows for
 *    telephony.livekit.sip.identify_match). With the default
 *    identify_by (username,ip) any INVITE with `From: livekit` would
 *    land in from-livekit, whose handoff-* extensions dial out through
 *    the carrier.
 *  - `anonymous` (seeded by a migration): only its context changes,
 *    to telephony.anonymous_context — from-trunk behind the Kamailio
 *    edge, unknown-reject otherwise.
 *
 * Idempotent; run by `orbital:sync-livekit-sip` on every deploy.
 */
class InfrastructureEndpointSyncer
{
    public function sync(): void
    {
        $host = (string) config('telephony.livekit.sip.host', 'livekit-sip');
        $port = (int) config('telephony.livekit.sip.port', 5069);
        $matches = $this->identifyMatches($host);

        DB::transaction(function () use ($host, $port, $matches) {
            $this->upsert('ps_endpoints', 'livekit', [
                'transport' => 'transport-udp',
                'aors' => 'livekit',
                'context' => 'from-livekit',
                'disallow' => 'all',
                'allow' => 'ulaw,alaw',
                'direct_media' => 'no',
                'rtp_symmetric' => 'yes',
                'force_rport' => 'yes',
                'dtmf_mode' => 'rfc4733',
                'identify_by' => 'ip',
            ]);
            $this->upsert('ps_aors', 'livekit', [
                'contact' => "sip:{$host}:{$port}",
            ]);
            $this->syncIdentifyRows($matches);

            DB::table('ps_endpoints')->where('id', 'anonymous')->update([
                'context' => (string) config('telephony.anonymous_context', 'unknown-reject'),
            ]);
        });
    }

    /**
     * One identify row per address: `livekit`, `livekit_2`, ... Rows
     * for addresses no longer configured are removed.
     *
     * @param  list<string>  $matches
     */
    protected function syncIdentifyRows(array $matches): void
    {
        $ids = [];
        foreach ($matches as $i => $match) {
            $id = $i === 0 ? 'livekit' : 'livekit_'.($i + 1);
            $ids[] = $id;
            $this->upsert('ps_endpoint_id_ips', $id, [
                'endpoint' => 'livekit',
                'match' => $match,
                'srv_lookups' => 'no',
            ]);
        }

        DB::table('ps_endpoint_id_ips')
            ->where('endpoint', 'livekit')
            ->whereNotIn('id', $ids)
            ->delete();
    }

    /**
     * Addresses the bridge sends SIP from: a comma-separated list of
     * IPs, CIDRs or hostnames (several bridges in HA), defaulting to the
     * bridge host Asterisk dials.
     *
     * @return list<string>
     */
    protected function identifyMatches(string $host): array
    {
        $configured = config('telephony.livekit.sip.identify_match');
        $list = is_array($configured) ? $configured : explode(',', (string) $configured);
        $list = array_values(array_unique(array_filter(array_map(
            fn ($m) => trim((string) $m),
            $list,
        ), fn (string $m) => $m !== '')));

        return $list !== [] ? $list : [$host];
    }

    /** @param array<string, mixed> $row */
    protected function upsert(string $table, string $id, array $row): void
    {
        DB::table($table)->updateOrInsert(['id' => $id], $row);
    }
}
