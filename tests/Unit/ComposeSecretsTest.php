<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The Compose stack used to ship a fixed AMI secret, LiveKit key pair and
 * Valkey password, with AMI and the LiveKit API published on every
 * interface. These tests pin the fix: no working default in the tree,
 * Compose and Asterisk refuse to start without per-install values,
 * bin/orbital-secrets generates them, and the sensitive ports stay on
 * loopback.
 */
class ComposeSecretsTest extends TestCase
{
    /**
     * SHA-256 of the values that used to be committed. Kept in sync with
     * bin/orbital-secrets and docker/asterisk/entrypoint.sh.
     */
    private const BURNED_SHA256 = [
        '918493c2bd7f85ed3109adf87fec92460ee84694230625b1a311c3f5271517d4', // AMI secret
        'c7ab4d29e6d196a3e82247cdf767ed698342cd55676eac314a6572ecff7e3608', // LiveKit key
        '4fddc81877e6cb752b42c127559bca1a4456c5af04737d097ab9697092c46bc5', // LiveKit secret
        '00674b3ae19c56019f797c5b91ddee0c3f7f1d40a22311a2f869bf7fce3491da', // Valkey password
    ];

    private const MANAGED = ['ASTERISK_AMI_SECRET', 'LIVEKIT_API_KEY', 'LIVEKIT_API_SECRET', 'REDIS_PASSWORD'];

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/orbital-secrets-'.bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp.'/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmp);
        parent::tearDown();
    }

    public function test_no_formerly_committed_secret_is_in_the_compose_tree(): void
    {
        $files = array_merge(
            [$this->path('docker-compose.yml'), $this->path('.env.example')],
            glob($this->path('compose/*.yml')) ?: [],
            $this->filesUnder($this->path('docker')),
        );

        foreach ($files as $file) {
            preg_match_all('/[A-Za-z0-9]{16,}/', (string) file_get_contents($file), $m);
            foreach (array_unique($m[0]) as $token) {
                $this->assertNotContains(
                    hash('sha256', $token),
                    self::BURNED_SHA256,
                    "{$file} still contains a secret that used to be committed",
                );
            }
        }
    }

    public function test_manager_conf_has_a_placeholder_secret_private_permits_and_no_system_write(): void
    {
        $conf = (string) file_get_contents($this->path('docker/asterisk/manager.conf'));

        $this->assertMatchesRegularExpression('/^secret = UNSET-/m', $conf);
        $this->assertDoesNotMatchRegularExpression('#^permit\s*=\s*0\.0\.0\.0/#m', $conf);
        $this->assertMatchesRegularExpression('#^deny\s*=\s*0\.0\.0\.0/0\.0\.0\.0#m', $conf);

        preg_match('/^write\s*=\s*(.+)$/m', $conf, $m);
        $write = array_map('trim', explode(',', $m[1] ?? ''));
        // Command (reloads, `core show channels`) and Originate are what
        // AsteriskAmiService and the config-sync sidecar send.
        $this->assertContains('command', $write);
        $this->assertContains('originate', $write);
        foreach (['system', 'config', 'dialplan'] as $class) {
            $this->assertNotContains($class, $write);
        }
    }

    public function test_every_asterisk_service_requires_the_ami_secret(): void
    {
        foreach (['docker-compose.yml', 'compose/ha-telephony.yml'] as $file) {
            $yaml = (string) file_get_contents($this->path($file));
            preg_match_all('/^    (asterisk-\d+):$/m', $yaml, $services);
            $this->assertNotEmpty($services[1], "{$file} defines no Asterisk service");

            $required = substr_count($yaml, "ASTERISK_AMI_SECRET: '\${ASTERISK_AMI_SECRET:?");
            $this->assertSame(count($services[1]), $required, "{$file}: every Asterisk needs ASTERISK_AMI_SECRET");
        }
    }

    public function test_livekit_config_comes_from_env_not_committed_files(): void
    {
        $yaml = (string) file_get_contents($this->path('docker-compose.yml'));

        $this->assertFileDoesNotExist($this->path('docker/livekit/livekit.yaml'));
        $this->assertFileDoesNotExist($this->path('docker/livekit/sip-config.yaml'));
        $this->assertStringContainsString('"${LIVEKIT_API_KEY:?', $yaml);
        $this->assertStringContainsString('"${LIVEKIT_API_SECRET:?', $yaml);
        $this->assertStringContainsString('"${REDIS_PASSWORD:?', $yaml);
    }

    public function test_sensitive_ports_are_published_on_loopback(): void
    {
        $yaml = (string) file_get_contents($this->path('docker-compose.yml'));
        preg_match_all("/^\s+- '([^']+)'$/m", $yaml, $m);

        $checked = [];
        foreach ($m[1] as $mapping) {
            // Resolve `${VAR:-default}` to its default (marking a loopback
            // default), then split host[:hostPort]:containerPort[/proto].
            $resolved = preg_replace_callback(
                '/\$\{[A-Z_]+:-([^}]*)\}/',
                fn (array $d) => $d[1] === '127.0.0.1' ? 'LOOPBACK-DEFAULT' : $d[1],
                $mapping,
            );
            if (! preg_match('#^(?:(.+):)?([^:]+):(\d+)(?:/\w+)?$#', (string) $resolved, $p)) {
                continue;
            }
            $container = (int) $p[3];
            if (! in_array($container, [5038, 7880, 7881, 5069, 3100, 11434, 443], true)) {
                continue;
            }
            $host = $p[1];
            if ($container === 443 && ! str_contains($mapping, 'PGADMIN')) {
                continue; // nginx-tls serves 443 publicly by design.
            }
            $this->assertMatchesRegularExpression(
                '/^(127\.0\.0\.1|LOOPBACK-DEFAULT)$/',
                $host,
                "port mapping '{$mapping}' must default to loopback",
            );
            $checked[] = $container;
        }

        sort($checked);
        $this->assertSame([443, 3100, 5038, 5069, 5069, 7880, 7881, 11434], $checked);
    }

    public function test_orbital_secrets_fills_empty_values_and_leaves_set_ones(): void
    {
        $env = $this->tmp.'/env';
        file_put_contents($env, "APP_KEY=keep\nASTERISK_AMI_SECRET=\nLIVEKIT_API_KEY=\nREDIS_PASSWORD=already-set\n# a comment\n");

        $this->runSecrets($env)->mustRun();
        $values = $this->parse($env);

        $this->assertSame('keep', $values['APP_KEY']);
        $this->assertSame('already-set', $values['REDIS_PASSWORD']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $values['ASTERISK_AMI_SECRET']);
        $this->assertMatchesRegularExpression('/^API[0-9a-f]{12}$/', $values['LIVEKIT_API_KEY']);
        // Appended because it was missing; LiveKit needs >= 32 chars.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $values['LIVEKIT_API_SECRET']);
        $this->assertStringContainsString("# a comment\n", (string) file_get_contents($env));

        $before = file_get_contents($env);
        $this->runSecrets($env)->mustRun();
        $this->assertSame($before, file_get_contents($env), 'a second run must change nothing');
    }

    public function test_orbital_secrets_rotates_the_livekit_pair_together(): void
    {
        $env = $this->tmp.'/env';
        file_put_contents($env, "ASTERISK_AMI_SECRET=a\nLIVEKIT_API_KEY=k\nLIVEKIT_API_SECRET=s\nREDIS_PASSWORD=r\n");

        $this->runSecrets($env, ['--rotate', 'LIVEKIT_API_SECRET'])->mustRun();
        $values = $this->parse($env);

        $this->assertNotSame('k', $values['LIVEKIT_API_KEY']);
        $this->assertNotSame('s', $values['LIVEKIT_API_SECRET']);
        $this->assertSame('a', $values['ASTERISK_AMI_SECRET']);
        $this->assertSame('r', $values['REDIS_PASSWORD']);
    }

    public function test_orbital_secrets_rejects_unknown_variables(): void
    {
        $env = $this->tmp.'/env';
        file_put_contents($env, "APP_KEY=x\n");

        $process = $this->runSecrets($env, ['--rotate', 'APP_KEY']);
        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertSame("APP_KEY=x\n", file_get_contents($env));
    }

    public function test_asterisk_entrypoint_refuses_to_start_without_a_usable_ami_secret(): void
    {
        foreach (['' => 'is not set', 'has space' => 'must not contain', 'semi;colon' => 'must not contain'] as $secret => $message) {
            $process = new Process(
                ['sh', $this->path('docker/asterisk/entrypoint.sh')],
                env: ['ASTERISK_AMI_SECRET' => $secret, 'PATH' => (string) getenv('PATH')],
            );
            $process->run();

            $this->assertSame(1, $process->getExitCode(), "secret '{$secret}' must be refused");
            $this->assertStringContainsString($message, $process->getErrorOutput());
        }
    }

    /**
     * @param  list<string>  $args
     */
    private function runSecrets(string $envFile, array $args = []): Process
    {
        return new Process(
            ['sh', $this->path('bin/orbital-secrets'), ...$args],
            env: ['ORBITAL_ENV_FILE' => $envFile, 'PATH' => (string) getenv('PATH')],
        );
    }

    /**
     * @return array<string, string>
     */
    private function parse(string $envFile): array
    {
        $values = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m)) {
                $values[$m[1]] = $m[2];
            }
        }
        foreach (self::MANAGED as $key) {
            $values[$key] ??= '';
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function filesUnder(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            // Generated Asterisk config is gitignored runtime output.
            if ($file->isFile() && ! str_contains($file->getPathname(), '/docker/asterisk/config/')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }
}
