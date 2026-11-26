<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards in the shared SIP edge config (docker/kamailio/kamailio.cfg) and
 * the local edge that runs it. Kamailio itself isn't available to the test
 * suite, so these pin the security-relevant pieces of the script text; the
 * behaviour was checked against a throwaway kamailio 5.5 container.
 */
class KamailioEdgeConfigTest extends TestCase
{
    private function repoFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $this->assertIsString($contents, "{$path} is readable");

        return $contents;
    }

    private function cfg(): string
    {
        return $this->repoFile('docker/kamailio/kamailio.cfg');
    }

    /** The body of a named route block, up to the next top-level block. */
    private function block(string $header): string
    {
        $cfg = $this->cfg();
        $start = strpos($cfg, $header);
        $this->assertNotFalse($start, "{$header} exists");
        $end = strpos($cfg, "\n}\n", $start);

        return substr($cfg, $start, $end - $start);
    }

    public function test_json_rpc_is_refused_off_the_management_port_and_network(): void
    {
        $cfg = $this->cfg();
        $this->assertStringContainsString('loadmodule "ipops.so"', $cfg);
        $this->assertMatchesRegularExpression(
            '/#!ifndef JSONRPC_ALLOWED_NETS\n#!define JSONRPC_ALLOWED_NETS "127\.0\.0\.0\/8,10\.0\.0\.0\/8,172\.16\.0\.0\/12,192\.168\.0\.0\/16"\n#!endif/',
            $cfg,
        );

        $http = $this->block('event_route[xhttp:request] {');
        $guard = strpos($http, 'if ($Rp != 8090 || !is_in_subnet("$si", JSONRPC_ALLOWED_NETS)) {');
        $dispatch = strpos($http, 'jsonrpc_dispatch();');
        $this->assertNotFalse($guard, 'port + source guard present');
        $this->assertNotFalse($dispatch);
        $this->assertLessThan($dispatch, $guard, 'guard runs before jsonrpc_dispatch()');
        $this->assertStringContainsString('xhttp_reply("403"', substr($http, $guard, $dispatch - $guard));

        // The WebSocket listener still upgrades before the guard.
        $this->assertLessThan($guard, strpos($http, 'ws_handle_handshake()'));
    }

    public function test_in_dialog_requests_need_a_known_dialog_or_a_backend(): void
    {
        $cfg = $this->cfg();
        $this->assertStringContainsString('modparam("dialog", "dlg_match_mode", 1)', $cfg);

        $request = $this->block('request_route {');
        $inDialog = substr($request, strpos($request, 'if (has_totag()) {'));
        $check = strpos($inDialog, 'if (!FROM_ASTERISK && !is_known_dlg() && !route(TO_BACKEND)) {');
        $this->assertNotFalse($check);
        $this->assertLessThan(strpos($inDialog, 't_relay();'), $check, 'checked before relaying');
        $this->assertLessThan(strpos($inDialog, 'rtpengine_manage('), $check, 'checked before touching rtpengine');
        // The local edge pins in-dialog requests to the dispatcher first,
        // so the backend check sees where they will really go.
        $this->assertLessThan($check, strpos($inDialog, '#!ifdef PIN_INDIALOG_TO_BACKEND'));

        $toBackend = $this->block('route[TO_BACKEND] {');
        $this->assertStringContainsString('ds_is_from_list("1", "3", "$var(nexthop)")', $toBackend);
    }

    public function test_outside_invites_cannot_claim_internal_identities(): void
    {
        $request = $this->block('request_route {');
        $invite = substr($request, strpos($request, '# --- Initial INVITE ---'));
        $guard = strpos($invite, 'if (!FROM_ASTERISK && ($fU =~ "^(livekit$|trunk_)" || $rU =~ "^handoff-")) {');
        $this->assertNotFalse($guard);
        $this->assertLessThan(strpos($invite, 'ds_select_dst("1", "0")'), $guard, 'refused before dispatch');
        $this->assertStringContainsString('sl_send_reply("403"', substr($invite, $guard, 400));
    }

    public function test_local_edge_keeps_unauthenticated_control_ports_on_loopback(): void
    {
        $compose = $this->repoFile('local/edge/docker-compose.yml');
        $this->assertStringContainsString('"${EDGE_JSONRPC_PUBLISH:-127.0.0.1:8090}:8090/tcp"', $compose);
        $this->assertStringContainsString('"${EDGE_RTPENGINE_NG_PUBLISH:-127.0.0.1:22222}:22222/udp"', $compose);
        $this->assertStringNotContainsString('"22222:22222/udp"', $compose);

        $edgeUp = $this->repoFile('local/edge-up.sh');
        $this->assertStringContainsString('#!define JSONRPC_ALLOWED_NETS', $edgeUp);
    }
}
