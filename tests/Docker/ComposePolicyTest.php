<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Docker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Server\Docker\ComposePolicy;
use Quire\Server\Docker\PolicyContext;

/**
 * The agent's gate. The configurations are shaped like "docker compose config --format json
 * --no-env-resolution" prints them (checked against Compose 5.6): a site made from the skeleton with
 * the server's override, and every way we know of to reach the host or other sites from it.
 */
final class ComposePolicyTest extends TestCase
{
    private string $root;
    private string $site;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quire-policy-'.bin2hex(random_bytes(6));
        $this->site = $this->root.'/sites/jane';
        mkdir($this->site, 0o700, true);
        mkdir($this->root.'/sites/other', 0o700, true);
        touch($this->root.'/sites/other/prod.env');
        touch($this->site.'/prod.env');
        // A link inside the site's folder to another site's secrets.
        symlink($this->root.'/sites/other/prod.env', $this->site.'/innocent.env');
        symlink($this->root.'/sites/other', $this->site.'/linked-folder');
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (['sites/jane/innocent.env', 'sites/jane/linked-folder', 'sites/jane/prod.env', 'sites/other/prod.env'] as $file) {
            @unlink($this->root.'/'.$file);
        }

        foreach (['sites/jane', 'sites/other', 'sites', ''] as $folder) {
            @rmdir($this->root.'/'.$folder);
        }
    }

    public function testASiteFromTheSkeletonWithTheServersOverridePasses(): void
    {
        self::assertSame([], $this->violations($this->site()));
    }

    /**
     * Changes to a valid configuration, each giving a site a way to the host or to others.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function hostile(): iterable
    {
        yield 'privileged' => [['services' => ['php' => ['privileged' => true]]], '"privileged" runs privileged'];
        yield 'capabilities' => [['services' => ['php' => ['cap_add' => ['NET_BIND_SERVICE', 'SYS_ADMIN']]]], '"cap_add: SYS_ADMIN" adds a Linux capability'];
        yield 'capabilities, spelled CAP_' => [['services' => ['php' => ['cap_add' => ['CAP_SYS_PTRACE']]]], '"cap_add: CAP_SYS_PTRACE"'];
        yield 'all capabilities' => [['services' => ['php' => ['cap_add' => ['ALL']]]], '"cap_add: ALL"'];
        yield 'host PID' => [['services' => ['php' => ['pid' => 'host']]], '"pid: host"'];
        yield 'host network' => [['services' => ['php' => ['network_mode' => 'host']]], '"network_mode: host"'];
        yield 'another container\'s network' => [['services' => ['php' => ['network_mode' => 'container:quire-server-agent-1']]], '"network_mode: container:quire-server-agent-1"'];
        yield 'host IPC' => [['services' => ['php' => ['ipc' => 'host']]], '"ipc: host"'];
        yield 'host user namespace' => [['services' => ['php' => ['userns_mode' => 'host']]], '"userns_mode: host"'];
        yield 'host cgroup' => [['services' => ['php' => ['cgroup' => 'host']]], '"cgroup: host"'];
        yield 'devices' => [['services' => ['php' => ['devices' => [['source' => '/dev/sda', 'target' => '/dev/sda', 'permissions' => 'rwm']]]]], '"devices" gives access to host devices'];
        yield 'GPUs' => [['services' => ['php' => ['gpus' => [['count' => -1]]]]], '"gpus"'];
        yield 'AppArmor off' => [['services' => ['php' => ['security_opt' => ['apparmor=unconfined']]]], '"security_opt: apparmor=unconfined"'];
        yield 'seccomp off' => [['services' => ['php' => ['security_opt' => ['seccomp:unconfined']]]], '"security_opt: seccomp:unconfined"'];
        yield 'kernel parameters' => [['services' => ['php' => ['sysctls' => ['net.ipv4.ip_forward' => '1']]]], '"sysctls"'];
        yield 'another runtime' => [['services' => ['php' => ['runtime' => 'nvidia']]], '"runtime: nvidia"'];
        yield 'cgroup parent' => [['services' => ['php' => ['cgroup_parent' => '/']]], '"cgroup_parent"'];
        yield 'the Docker socket (Compose 2.37+)' => [['services' => ['php' => ['use_api_socket' => true]]], '"use_api_socket" hands the container the Docker socket'];
        yield 'a provider program' => [['services' => ['php' => ['provider' => ['type' => 'awesomecloud']]]], '"provider" runs a program'];
        yield 'a privileged hook' => [['services' => ['php' => ['post_start' => [['command' => ['id'], 'privileged' => true]]]]], '"post_start" runs a privileged command'];
        yield 'a fixed container name' => [['services' => ['php' => ['container_name' => 'quire-server-database-1']]], '"container_name"'];
        yield 'other containers\' volumes' => [['services' => ['php' => ['volumes_from' => ['container:quire-server-database-1']]]], '"volumes_from"'];
        yield 'legacy links' => [['services' => ['php' => ['links' => ['database']]]], '"links"'];
        yield 'published ports' => [['services' => ['php' => ['ports' => [['target' => 80, 'published' => '80', 'protocol' => 'tcp']]]]], '"ports"'];
        yield 'syslog to the host' => [['services' => ['php' => ['logging' => ['driver' => 'syslog']]]], '"logging: syslog"'];
        yield 'out-of-memory protection' => [['services' => ['php' => ['oom_score_adj' => -1000]]], '"oom_score_adj" below 0'];
        yield 'GPU reservations' => [['services' => ['php' => ['deploy' => ['resources' => ['reservations' => ['devices' => [['capabilities' => ['gpu']]]]]]]]], 'reservations.devices'];
        yield 'an unknown setting' => [['services' => ['php' => ['brand_new_power' => true]]], '"brand_new_power" isn\'t a setting the server allows'];
        yield 'an unknown top-level setting' => [['models' => ['llm' => ['model' => 'ai/x']]], '"models": a top-level setting the server doesn\'t allow'];
        yield 'another project name' => [['name' => 'quire-server'], 'The project must be named "jane"'];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('hostile')]
    public function testWhatReachesTheHostIsRefused(array $change, string $expected): void
    {
        $violations = $this->violations(array_replace_recursive($this->site(), $change));

        self::assertNotSame([], $violations);
        self::assertStringContainsString($expected, implode("\n", $violations));
    }

    public function testHostPathsAndOtherProjectsDataCantBeMounted(): void
    {
        // (The site's own three volumes are at 0 to 2: these come after.)
        $config = array_replace_recursive($this->site(), [
            'services' => ['php' => ['volumes' => [
                3 => ['type' => 'bind', 'source' => '/', 'target' => '/host', 'bind' => []],
                4 => ['type' => 'bind', 'source' => $this->site.'/data', 'target' => '/data'],
                5 => ['type' => 'npipe', 'source' => '\\\\.\\pipe\\docker_engine', 'target' => '/pipe'],
                6 => ['type' => 'volume', 'source' => 'stolen', 'target' => '/stolen', 'volume' => []],
            ]]],
            'volumes' => [
                'stolen' => ['name' => 'other_storage_data', 'external' => true],
                'renamed' => ['name' => 'other_storage_data'],
                'sneaky' => ['name' => 'jane_sneaky', 'driver_opts' => ['type' => 'none', 'o' => 'bind', 'device' => '/']],
                'plugin' => ['name' => 'jane_plugin', 'driver' => 'sshfs'],
            ],
        ]);

        $violations = implode("\n", $this->violations($config));

        self::assertStringContainsString('A "bind" mount of "/"', $violations);
        self::assertStringContainsString(\sprintf('A "bind" mount of "%s/data"', $this->site), $violations, 'Even inside the site\'s folder: the container could replace its files.');
        self::assertStringContainsString('A "npipe" mount', $violations);
        self::assertStringContainsString('Volume "stolen": external volumes aren\'t allowed', $violations);
        self::assertStringContainsString('Volume "renamed": its name "other_storage_data" isn\'t in the project\'s own', $violations);
        self::assertStringContainsString('Volume "sneaky": "driver_opts" aren\'t allowed', $violations);
        self::assertStringContainsString('Volume "plugin": the "sshfs" driver', $violations);
    }

    public function testFilesOutsideTheSiteCantBeRead(): void
    {
        $config = array_replace_recursive($this->site(), [
            'services' => [
                'php' => ['env_file' => [['path' => $this->root.'/sites/other/prod.env'], ['path' => $this->site.'/innocent.env'], ['path' => $this->site.'/linked-folder/prod.env'], ['path' => $this->site.'/../other/prod.env'], ['path' => 'relative.env'], ['path' => $this->site.'/prod.env']]],
                'worker' => ['label_file' => ['/etc/passwd']],
            ],
            'secrets' => ['server' => ['file' => '/srv/quire/server/server.env'], 'remote' => ['name' => 'x', 'external' => true], 'own' => ['file' => $this->site.'/prod.env']],
            'configs' => ['hosts' => ['file' => '/etc/hosts']],
        ]);

        $violations = $this->violations($config);
        $text = implode("\n", $violations);

        self::assertStringContainsString(\sprintf('"env_file: %s/sites/other/prod.env" is outside', $this->root), $text);
        self::assertStringContainsString(\sprintf('"env_file: %s/innocent.env" is outside', $this->site), $text, 'A symbolic link to another site\'s secrets.');
        self::assertStringContainsString(\sprintf('"env_file: %s/linked-folder/prod.env" is outside', $this->site), $text, 'A linked folder.');
        self::assertStringContainsString('/../other/prod.env" is outside', $text);
        self::assertStringContainsString('"env_file: relative.env" is outside', $text, 'Paths must be absolute, as Compose resolves them.');
        self::assertStringNotContainsString(\sprintf('"env_file: %s/prod.env"', $this->site), $text, 'The site\'s own file is fine.');
        self::assertStringContainsString('"label_file: /etc/passwd" is outside', $text);
        self::assertStringContainsString('Secret "server": its file is outside', $text);
        self::assertStringContainsString('Secret "remote": external ones', $text);
        self::assertStringNotContainsString('Secret "own"', $text);
        self::assertStringContainsString('Config "hosts": its file is outside', $text);
    }

    public function testBuildsStayInTheSitesFolder(): void
    {
        $config = array_replace_recursive($this->site(), ['services' => [
            'php' => ['build' => ['context' => '/', 'dockerfile' => 'Dockerfile']],
            'worker' => ['build' => ['context' => $this->site, 'dockerfile' => '../other/Dockerfile', 'additional_contexts' => ['x' => '/etc'], 'ssh' => ['default'], 'secrets' => ['s'], 'entitlements' => ['network.host'], 'cache_to' => ['type=local,dest=/tmp'], 'network' => 'host', 'privileged' => true]],
        ]]);

        $text = implode("\n", $this->violations($config));

        self::assertStringContainsString('Service "php": it builds from "/", outside', $text);
        self::assertStringContainsString('Service "worker": its Dockerfile is outside', $text);

        foreach (['additional_contexts', 'ssh', 'secrets', 'entitlements', 'cache_to', 'privileged'] as $key) {
            self::assertStringContainsString(\sprintf('("%s")', $key), $text);
        }

        self::assertStringContainsString('its build uses the network "host"', $text);
    }

    public function testSharedNetworksOnlyWithTheSitesOwnNames(): void
    {
        // Taking another site's domain on the front door's network, or the database's name on the database's network.
        $config = array_replace_recursive($this->site(), [
            'services' => [
                'php' => ['networks' => ['quire-edge' => ['aliases' => [2 => 'other.example']]]],
                'worker' => ['networks' => ['quire-data' => ['aliases' => ['quire-database'], 'ipv4_address' => '172.30.0.5']]],
                'evil' => ['image' => 'alpine', 'networks' => ['quire-edge' => []]],
            ],
            'networks' => [
                'sneaky' => ['name' => 'quire-server_default', 'external' => true],
                'own-renamed' => ['name' => 'other_default'],
                'macvlan' => ['name' => 'jane_macvlan', 'driver' => 'macvlan'],
                'quire-edge-copy' => ['name' => 'quire-edge'],
            ],
        ]);

        $text = implode("\n", $this->violations($config));

        self::assertStringContainsString('Service "php": the alias "other.example" on the shared network "quire-edge" isn\'t one of its own', $text);
        self::assertStringContainsString('Service "worker": the alias "quire-database" on the shared network "quire-data"', $text);
        self::assertStringContainsString('Service "worker": "ipv4_address" on the shared network "quire-data"', $text);
        self::assertStringContainsString('Service "evil": it may not join the shared network "quire-edge"', $text);
        self::assertStringContainsString('Network "sneaky": the external network "quire-server_default" isn\'t one the server shares', $text);
        self::assertStringContainsString('Network "own-renamed": its name "other_default"', $text);
        self::assertStringContainsString('Network "macvlan": the "macvlan" driver', $text);
        self::assertStringContainsString('Network "quire-edge-copy": its name "quire-edge"', $text, 'A network of its own can\'t take a shared one\'s name.');
    }

    public function testHarmlessValuesOfForbiddenSettingsPass(): void
    {
        $config = array_replace_recursive($this->site(), ['services' => ['php' => ['privileged' => false, 'cap_add' => ['NET_BIND_SERVICE', 'cap_net_bind_service'], 'devices' => [], 'security_opt' => ['no-new-privileges:true'], 'cap_drop' => ['ALL'], 'oom_score_adj' => 100, 'logging' => ['driver' => 'json-file'], 'x-custom' => 'anything']]]);

        self::assertSame([], $this->violations($config));
    }

    /**
     * @param array<mixed> $config
     *
     * @return list<string>
     */
    private function violations(array $config): array
    {
        return new ComposePolicy()->violations($config, new PolicyContext('jane', $this->site, [
            'quire-edge' => ['php' => ['jane.example', 'www.jane.example']],
            'quire-data' => ['php' => [], 'worker' => []],
        ]));
    }

    /**
     * The skeleton's production setup with the server's override, as Compose resolves it.
     *
     * @return array<string, mixed>
     */
    private function site(): array
    {
        $build = ['context' => $this->site, 'dockerfile' => 'Dockerfile', 'target' => 'frankenphp_prod'];
        $networks = ['default' => null, 'quire-data' => null];

        return [
            'name' => 'jane',
            'networks' => [
                'default' => ['name' => 'jane_default', 'ipam' => []],
                'quire-edge' => ['name' => 'quire-edge', 'ipam' => [], 'external' => true],
                'quire-data' => ['name' => 'quire-data', 'ipam' => [], 'external' => true],
            ],
            'services' => [
                'php' => [
                    'build' => $build,
                    'image' => 'jane-php-prod',
                    'command' => null,
                    'entrypoint' => null,
                    'environment' => ['SERVER_NAME' => 'http://jane.example, http://www.jane.example, php:80', 'APP_SECRET' => 'x'],
                    'labels' => ['com.quirecms.site' => 'true', 'com.quirecms.server.site' => 'jane'],
                    'networks' => $networks + ['quire-edge' => ['aliases' => ['jane.example', 'www.jane.example']]],
                    'restart' => 'unless-stopped',
                    'security_opt' => ['no-new-privileges:true'],
                    'cap_drop' => ['ALL'],
                    'mem_limit' => '1073741824',
                    'pids_limit' => 512,
                    'volumes' => [
                        ['type' => 'volume', 'source' => 'caddy_data', 'target' => '/data', 'volume' => []],
                        ['type' => 'volume', 'source' => 'caddy_config', 'target' => '/config', 'volume' => []],
                        ['type' => 'volume', 'source' => 'storage_data', 'target' => '/app/storage', 'volume' => []],
                    ],
                ],
                'worker' => [
                    'build' => $build,
                    'image' => 'jane-php-prod',
                    'command' => ['php', 'bin/console', 'messenger:consume', 'quire'],
                    'depends_on' => ['php' => ['condition' => 'service_healthy', 'required' => true]],
                    'healthcheck' => ['test' => ['CMD', 'php', '-r', 'exit(0);']],
                    'networks' => $networks,
                    'restart' => 'unless-stopped',
                    'volumes' => [['type' => 'volume', 'source' => 'storage_data', 'target' => '/app/storage', 'volume' => []]],
                ],
            ],
            'volumes' => [
                'caddy_config' => ['name' => 'jane_caddy_config'],
                'caddy_data' => ['name' => 'jane_caddy_data'],
                'storage_data' => ['name' => 'jane_storage_data'],
            ],
        ];
    }
}
