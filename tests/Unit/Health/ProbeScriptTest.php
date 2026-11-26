<?php

declare(strict_types=1);

namespace Tests\Unit\Health;

use App\Services\Health\SystemHealthService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The health probes shell out to bash. A host must never be parsed as
 * shell code, whatever an admin typed into a probe target.
 */
class ProbeScriptTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function hosts(): array
    {
        return [
            'dns name' => ['asterisk', true],
            'fqdn' => ['sip-edge.example.com', true],
            'ipv4' => ['10.0.0.5', true],
            'ipv6' => ['::1', true],
            'bracketed ipv6' => ['[2001:db8::1]', true],
            'underscore service name' => ['orbital_valkey', true],
            'empty' => ['', false],
            'command substitution' => ['$(id)', false],
            'backticks' => ['`id`', false],
            'single quote' => ["a'b", false],
            'semicolon' => ['a;id', false],
            'space' => ['a b', false],
            'option-looking' => ['-oProxyCommand', false],
        ];
    }

    #[DataProvider('hosts')]
    public function test_host_validation(string $host, bool $expected): void
    {
        $this->assertSame($expected, SystemHealthService::isProbeableHost($host));
    }

    public function test_host_reaches_inner_shell_as_a_positional_argument(): void
    {
        if (! is_executable('/bin/bash') && ! is_executable('/usr/bin/bash')) {
            $this->markTestSkipped('bash not available');
        }

        $marker = sys_get_temp_dir().'/orbital-probe-'.bin2hex(random_bytes(6));
        // Deliberately skip isProbeableHost(): the script itself must not
        // let the inner bash re-parse the host.
        $hostile = "x\$(touch {$marker})'\$(touch {$marker})'`touch {$marker}`";

        $script = SystemHealthService::buildProbeScript([
            'tcp' => ['host' => $hostile, 'port' => 1],
            'tls' => ['host' => $hostile, 'port' => 1, 'tls' => true],
        ], '1.0');

        $output = [];
        exec('bash -c '.escapeshellarg($script), $output);

        $this->assertFileDoesNotExist($marker);
        sort($output);
        $this->assertSame(['tcp:fail', 'tls:fail'], $output);
    }

    public function test_many_targets_build_a_bounded_script(): void
    {
        $targets = [];
        for ($i = 0; $i < 40; $i++) {
            $targets["probe_{$i}"] = ['host' => 'asterisk', 'port' => 5060];
        }

        $script = SystemHealthService::buildProbeScript($targets, '1.5');

        $this->assertSame(40, substr_count($script, "timeout '1.5' bash -c"));
        $this->assertLessThan(10000, strlen($script));
    }

    public function test_reachable_port_reads_ok(): void
    {
        if (! is_executable('/bin/bash') && ! is_executable('/usr/bin/bash')) {
            $this->markTestSkipped('bash not available');
        }

        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("cannot listen: {$errstr}");
        }
        $port = (int) substr((string) stream_socket_get_name($server, false), strrpos((string) stream_socket_get_name($server, false), ':') + 1);

        $output = [];
        exec('bash -c '.escapeshellarg(SystemHealthService::buildProbeScript([
            'local' => ['host' => '127.0.0.1', 'port' => $port],
        ], '1.0')), $output);

        fclose($server);
        $this->assertSame(['local:ok'], $output);
    }
}
