<?php

declare(strict_types=1);

namespace Quire\Server\Docker;

/**
 * The agent's gate before it builds or starts a site: the site's whole Compose configuration, as
 * "docker compose config --format json --no-env-resolution" resolves it (every file merged, paths
 * absolute), checked against what a site may do on a shared server.
 *
 * Access to the Docker API is root on the machine: whoever can create containers can create one
 * that mounts the host or runs privileged. Docker's own authorization plugins have been bypassed
 * (CVE-2024-41110, CVE-2026-34040), so this check is the boundary, and it fails closed: a setting
 * it doesn't know is refused until it has been reviewed, since new Compose versions keep adding
 * settings with host powers (e.g. "use_api_socket", which hands a container the Docker socket, or
 * "provider", which runs a program where Compose runs).
 *
 * A site may: build from its own folder, run containers from any image, use its own volumes and
 * networks, and join the server's shared networks only as its context allows (its domains as aliases
 * on the front door's network, nothing on the database's). It may not: run privileged or with extra
 * capabilities, share the host's namespaces, publish ports, mount host paths or other projects'
 * volumes, read files outside its folder, or take names that belong to others.
 */
final readonly class ComposePolicy
{
    /** Service settings without host powers: allowed as they are */
    private const array SAFE_SERVICE_KEYS = [
        'annotations', 'attach', 'cap_drop', 'cap_add', 'command', 'cpu_count', 'cpu_percent', 'cpu_period', 'cpu_quota', 'cpu_rt_period', 'cpu_rt_runtime', 'cpu_shares', 'cpus', 'cpuset',
        'depends_on', 'develop', 'dns', 'dns_opt', 'dns_search', 'domainname', 'entrypoint', 'environment', 'expose', 'extra_hosts', 'healthcheck', 'hostname', 'image', 'init',
        'labels', 'mac_address', 'mem_limit', 'mem_reservation', 'mem_swappiness', 'memswap_limit', 'pids_limit', 'platform', 'profiles', 'pull_policy', 'read_only', 'restart',
        'scale', 'shm_size', 'stdin_open', 'stop_grace_period', 'stop_signal', 'tmpfs', 'tty', 'ulimits', 'user', 'working_dir', 'secrets', 'configs',
    ];

    /** Service settings that are allowed only with the values checked below */
    private const array CHECKED_SERVICE_KEYS = [
        'build', 'blkio_config', 'cgroup', 'deploy', 'env_file', 'ipc', 'isolation', 'label_file', 'logging', 'network_mode', 'networks', 'oom_kill_disable', 'oom_score_adj',
        'pid', 'ports', 'post_start', 'pre_stop', 'runtime', 'security_opt', 'userns_mode', 'uts', 'volumes',
    ];

    /**
     * Capabilities a service may add back after dropping them all, each among Docker's defaults and with
     * no power over the host: binding ports below 1024 inside its own network namespace (FrankenPHP's
     * binary carries it as a file capability, and can't even start without it under
     * "no-new-privileges"), and what a database's entrypoint needs to own its data and become its user
     * (CHOWN, DAC_OVERRIDE, FOWNER, SETGID, SETUID). Never NET_RAW or NET_ADMIN (spoofing and
     * reconfiguring the networks other sites are on), nor any SYS_ one.
     */
    private const array ALLOWED_CAPABILITIES = ['NET_BIND_SERVICE', 'CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'SETGID', 'SETUID'];

    /** The most processes a container may run */
    private const int MAX_PIDS = 4096;

    /** Service settings no site may use */
    private const array FORBIDDEN_SERVICE_KEYS = [
        'cgroup_parent' => 'places the container in another control group',
        'container_name' => 'takes a fixed container name, which may belong to another project',
        'credential_spec' => 'uses host credentials',
        'device_cgroup_rules' => 'gives access to host devices',
        'devices' => 'gives access to host devices',
        'external_links' => 'links to containers of other projects',
        'gpus' => 'gives access to host GPUs',
        'links' => 'adds legacy links (use networks)',
        'models' => 'runs AI models through the host',
        'privileged' => 'runs privileged, with full access to the host',
        'provider' => 'runs a program where Compose runs',
        'storage_opt' => 'changes the host\'s storage driver options',
        'sysctls' => 'changes kernel parameters',
        'use_api_socket' => 'hands the container the Docker socket',
        'volumes_from' => 'mounts the volumes of other containers',
    ];

    private const array TOP_LEVEL_KEYS = ['name', 'services', 'volumes', 'networks', 'secrets', 'configs'];

    /**
     * The settings allowed below a service's, level by level: fail-closed there too, as a new setting
     * with host powers may come at any level (a build's, a mount's, a network's IPAM). Some are
     * checked further below; the rest have no power over the host or other sites.
     */
    private const array NESTED_KEYS = [
        'build' => ['context', 'dockerfile', 'dockerfile_inline', 'args', 'labels', 'target', 'tags', 'no_cache', 'pull', 'shm_size', 'extra_hosts', 'ulimits', 'platforms', 'provenance', 'sbom',
            // Checked in build()
            'network', 'isolation', 'additional_contexts', 'ssh', 'secrets', 'entitlements', 'cache_to', 'cache_from', 'privileged'],
        'deploy' => ['mode', 'replicas', 'labels', 'resources', 'restart_policy', 'update_config', 'rollback_config', 'placement', 'endpoint_mode'],
        'deploy.resources' => ['limits', 'reservations'],
        'deploy.resources.limits' => ['cpus', 'memory', 'pids'],
        'deploy.resources.reservations' => ['cpus', 'memory', 'devices'],
        'mount' => ['type', 'source', 'target', 'read_only', 'consistency', 'volume', 'tmpfs'],
        'mount.volume' => ['nocopy', 'subpath'],
        'mount.tmpfs' => ['size', 'mode'],
        'logging' => ['driver', 'options'],
        'blkio_config' => ['weight', 'weight_device', 'device_read_bps', 'device_write_bps', 'device_read_iops', 'device_write_iops'],
        'hook' => ['command', 'user', 'privileged', 'working_dir', 'environment'],
        'attachment' => ['aliases', 'ipv4_address', 'ipv6_address', 'link_local_ips', 'mac_address', 'driver_opts', 'priority', 'gw_priority', 'interface_name'],
        'reference' => ['source', 'target', 'uid', 'gid', 'mode'],
        'volume' => ['name', 'driver', 'driver_opts', 'external', 'labels'],
        'network' => ['name', 'driver', 'driver_opts', 'external', 'labels', 'ipam', 'internal', 'attachable', 'enable_ipv4', 'enable_ipv6'],
        'network.ipam' => ['driver', 'config', 'options'],
        'network.ipam.config' => ['subnet', 'ip_range', 'gateway', 'aux_addresses'],
        'secret' => ['name', 'file', 'environment', 'content', 'external', 'labels'],
    ];

    /**
     * @param array<mixed> $config What "docker compose config --format json --no-env-resolution" printed, decoded
     *
     * @return list<string> What's not allowed, each saying where; empty when the configuration passes
     */
    public function violations(array $config, PolicyContext $context): array
    {
        $violations = [];

        foreach (array_keys($config) as $key) {
            if (\is_string($key) && !\in_array($key, self::TOP_LEVEL_KEYS, true) && !str_starts_with($key, 'x-')) {
                $violations[] = \sprintf('"%s": a top-level setting the server doesn\'t allow.', $key);
            }
        }

        if (($config['name'] ?? null) !== $context->project) {
            $violations[] = \sprintf('The project must be named "%s".', $context->project);
        }

        $services = $this->map($config['services'] ?? []);

        foreach ($services as $name => $service) {
            foreach ($this->service((string) $name, $this->map($service), array_map(strval(...), array_keys($services)), $context) as $violation) {
                $violations[] = \sprintf('Service "%s": %s', $name, $violation);
            }
        }

        foreach ($this->map($config['volumes'] ?? []) as $name => $volume) {
            foreach ($this->volume($this->map($volume), $context) as $violation) {
                $violations[] = \sprintf('Volume "%s": %s', $name, $violation);
            }
        }

        foreach ($this->map($config['networks'] ?? []) as $name => $network) {
            foreach ($this->network((string) $name, $this->map($network), $context) as $violation) {
                $violations[] = \sprintf('Network "%s": %s', $name, $violation);
            }
        }

        foreach (['secrets', 'configs'] as $kind) {
            foreach ($this->map($config[$kind] ?? []) as $name => $item) {
                $item = $this->map($item);

                foreach ($this->unknown($item, 'secret', '') as $violation) {
                    $violations[] = \sprintf('%s "%s": %s', ucfirst(rtrim($kind, 's')), $name, $violation);
                }

                if ($this->true($item['external'] ?? false)) {
                    $violations[] = \sprintf('%s "%s": external ones aren\'t allowed.', ucfirst(rtrim($kind, 's')), $name);
                }

                if (isset($item['file']) && !$context->contains($this->string($item['file']))) {
                    $violations[] = \sprintf('%s "%s": its file is outside the site\'s folder.', ucfirst(rtrim($kind, 's')), $name);
                }
            }
        }

        return $violations;
    }

    /**
     * @param array<array-key, mixed> $service
     * @param list<string>            $services All the project's services
     *
     * @return list<string>
     */
    private function service(string $name, array $service, array $services, PolicyContext $context): array
    {
        $violations = [];

        foreach ($service as $key => $value) {
            $key = (string) $key;

            if (isset(self::FORBIDDEN_SERVICE_KEYS[$key])) {
                // An empty value (e.g. "privileged: false", "cap_add: []") asks for nothing.
                if (null !== $value && [] !== $value && '' !== $value && !$this->false($value)) {
                    $violations[] = \sprintf('"%s" %s.', $key, self::FORBIDDEN_SERVICE_KEYS[$key]);
                }
            } elseif (!\in_array($key, self::SAFE_SERVICE_KEYS, true) && !\in_array($key, self::CHECKED_SERVICE_KEYS, true) && !str_starts_with($key, 'x-')) {
                $violations[] = \sprintf('"%s" isn\'t a setting the server allows (yet).', $key);
            }
        }

        foreach ($this->list($service['cap_add'] ?? []) as $capability) {
            $added = strtoupper($this->string($capability));

            if (!\in_array(str_starts_with($added, 'CAP_') ? substr($added, 4) : $added, self::ALLOWED_CAPABILITIES, true)) {
                $violations[] = \sprintf('"cap_add: %s" adds a Linux capability (only %s may be added).', $this->string($capability), implode(', ', self::ALLOWED_CAPABILITIES));
            }
        }

        // Any other value joins a Docker network by its name, past the checks of "networks" below: the
        // host's, the default bridge, or the docker-proxy's (quire-docker), which is root on the host.
        $network = $this->string($service['network_mode'] ?? '');
        $sibling = str_starts_with($network, 'service:') ? substr($network, 8) : null;

        if ('' !== $network && 'none' !== $network && (null === $sibling || $sibling === $name || !\in_array($sibling, $services, true))) {
            $violations[] = \sprintf('"network_mode: %s": a service joins only its site\'s own networks, no network, or another of its services\' ("service:<name>").', $network);
        }

        foreach (['pid', 'ipc', 'uts', 'userns_mode', 'cgroup'] as $namespace) {
            $mode = $this->string($service[$namespace] ?? '');

            if ('host' === $mode || str_starts_with($mode, 'container:')) {
                $violations[] = \sprintf('"%s: %s" shares a namespace with the host or another container.', $namespace, $mode);
            }
        }

        array_push($violations, ...$this->hardening($service));

        foreach (['logging', 'blkio_config'] as $nested) {
            array_push($violations, ...$this->unknown($this->map($service[$nested] ?? []), $nested, $nested.'.'));
        }

        foreach (['secrets', 'configs'] as $kind) {
            foreach ($this->list($service[$kind] ?? []) as $reference) {
                array_push($violations, ...$this->unknown($this->map($reference), 'reference', $kind.'.'));
            }
        }

        $runtime = $this->string($service['runtime'] ?? '');

        if ('' !== $runtime && 'runc' !== $runtime) {
            $violations[] = \sprintf('"runtime: %s": only the default runtime is allowed.', $runtime);
        }

        $isolation = $this->string($service['isolation'] ?? '');

        if ('' !== $isolation && 'default' !== $isolation) {
            $violations[] = '"isolation": only the default is allowed.';
        }

        foreach ($this->list($service['security_opt'] ?? []) as $option) {
            if (1 !== preg_match('/^no-new-privileges(?:[:=]true)?\z/', $this->string($option))) {
                $violations[] = \sprintf('"security_opt: %s": only "no-new-privileges" is allowed.', $this->string($option));
            }
        }

        if ($this->true($service['oom_kill_disable'] ?? false)) {
            $violations[] = '"oom_kill_disable" lets the container take the host\'s memory.';
        }

        if (\is_int($service['oom_score_adj'] ?? null) && $service['oom_score_adj'] < 0) {
            $violations[] = '"oom_score_adj" below 0 protects the container at the others\' expense.';
        }

        foreach (['weight_device', 'device_read_bps', 'device_write_bps', 'device_read_iops', 'device_write_iops'] as $device) {
            if ([] !== $this->list($this->map($service['blkio_config'] ?? [])[$device] ?? [])) {
                $violations[] = '"blkio_config" names host devices.';

                break;
            }
        }

        $driver = $this->string($this->map($service['logging'] ?? [])['driver'] ?? '');

        if ('' !== $driver && !\in_array($driver, ['json-file', 'local', 'none'], true)) {
            $violations[] = \sprintf('"logging: %s": only the json-file, local and none drivers are allowed.', $driver);
        }

        if ([] !== $this->list($service['ports'] ?? [])) {
            $violations[] = '"ports": sites don\'t publish ports; the front door reaches them.';
        }

        foreach (['post_start', 'pre_stop'] as $hook) {
            foreach ($this->list($service[$hook] ?? []) as $handler) {
                array_push($violations, ...$this->unknown($this->map($handler), 'hook', $hook.'.'));

                if ($this->true($this->map($handler)['privileged'] ?? false)) {
                    $violations[] = \sprintf('"%s" runs a privileged command.', $hook);
                }
            }
        }

        foreach (['env_file', 'label_file'] as $files) {
            foreach ($this->list($service[$files] ?? []) as $file) {
                $path = \is_array($file) ? $this->string($file['path'] ?? '') : $this->string($file);

                if (!$context->contains($path)) {
                    $violations[] = \sprintf('"%s: %s" is outside the site\'s folder.', $files, $path);
                }
            }
        }

        $deploy = $this->map($service['deploy'] ?? []);
        $resources = $this->map($deploy['resources'] ?? []);
        array_push(
            $violations,
            ...$this->unknown($deploy, 'deploy', 'deploy.'),
            ...$this->unknown($resources, 'deploy.resources', 'deploy.resources.'),
            ...$this->unknown($this->map($resources['limits'] ?? []), 'deploy.resources.limits', 'deploy.resources.limits.'),
            ...$this->unknown($this->map($resources['reservations'] ?? []), 'deploy.resources.reservations', 'deploy.resources.reservations.'),
        );

        if ([] !== $this->list($this->map($resources['reservations'] ?? [])['devices'] ?? [])) {
            $violations[] = '"deploy.resources.reservations.devices" gives access to host devices.';
        }

        foreach ($this->list($service['volumes'] ?? []) as $mount) {
            $mount = $this->map($mount);
            $type = $this->string($mount['type'] ?? '');
            array_push(
                $violations,
                ...$this->unknown($mount, 'mount', 'volumes.'),
                ...$this->unknown($this->map($mount['volume'] ?? []), 'mount.volume', 'volumes.volume.'),
                ...$this->unknown($this->map($mount['tmpfs'] ?? []), 'mount.tmpfs', 'volumes.tmpfs.'),
            );

            if (!\in_array($type, ['volume', 'tmpfs'], true)) {
                $violations[] = \sprintf('A "%s" mount of "%s": only named volumes and tmpfs are allowed.', $type, $this->string($mount['source'] ?? ''));
            }
        }

        if (isset($service['build'])) {
            array_push($violations, ...$this->build($this->map($service['build']), $context));
        }

        array_push($violations, ...$this->images($service, $context));

        array_push($violations, ...$this->attachments($name, $this->map($service['networks'] ?? []), $context));

        return $violations;
    }

    /**
     * What every service carries, whoever wrote it (the server's override gives it to the services it
     * knows, a service a site adds says so itself): no capabilities but those added back, no gaining
     * privileges, limits on memory and processes, and one container. Without it a service has Docker's
     * defaults: NET_RAW, which spoofs other containers' addresses on the networks sites share, and no
     * limit on what it takes from the machine.
     *
     * @param array<array-key, mixed> $service
     *
     * @return list<string>
     */
    private function hardening(array $service): array
    {
        $violations = [];
        $dropped = array_map(fn (mixed $capability): string => strtoupper($this->string($capability)), $this->list($service['cap_drop'] ?? []));

        if (!\in_array('ALL', $dropped, true)) {
            $violations[] = '"cap_drop: [ALL]" is missing: every service drops Docker\'s default capabilities (NET_RAW among them).';
        }

        if ([] === array_filter($this->list($service['security_opt'] ?? []), fn (mixed $option): bool => 1 === preg_match('/^no-new-privileges(?:[:=]true)?\z/', $this->string($option)))) {
            $violations[] = '"security_opt: [no-new-privileges:true]" is missing.';
        }

        $deploy = $this->map($service['deploy'] ?? []);
        $limits = $this->map($this->map($deploy['resources'] ?? [])['limits'] ?? []);

        if (null === ($service['mem_limit'] ?? $limits['memory'] ?? null)) {
            $violations[] = '"mem_limit" is missing: every service has a memory limit.';
        }

        $pids = $service['pids_limit'] ?? $limits['pids'] ?? null;

        if (!is_numeric($pids) || (int) $pids < 1 || (int) $pids > self::MAX_PIDS) {
            $violations[] = \sprintf('"pids_limit" (1 to %d) is missing: every service has a limit on its processes.', self::MAX_PIDS);
        }

        foreach (['scale' => $service['scale'] ?? null, 'deploy.replicas' => $deploy['replicas'] ?? null] as $key => $count) {
            if (is_numeric($count) && (int) $count > 1) {
                $violations[] = \sprintf('"%s: %d": a site runs one container of each service.', $key, (int) $count);
            }
        }

        return $violations;
    }

    /**
     * The names of the images a service builds are the site's own: "quire-sites/<project>/…", which no
     * other project's can be (project names have no "/"). Another site's name would have its next start
     * (an undo, a rollback, a new domain) run this site's code with its secrets and domains. And no
     * service runs an image of another site's.
     *
     * @param array<array-key, mixed> $service
     *
     * @return list<string>
     */
    private function images(array $service, PolicyContext $context): array
    {
        $violations = [];
        $image = $this->string($service['image'] ?? '');
        $own = '#^'.preg_quote(PolicyContext::IMAGES.$context->project.'/', '#').'[a-z0-9]([a-z0-9._-]{0,126})(:[A-Za-z0-9_][A-Za-z0-9._-]{0,127})?\z#';

        if (isset($service['build'])) {
            if (1 !== preg_match($own, $image)) {
                $violations[] = \sprintf('the image it builds is named "%s": a site\'s images are named "%s%s/<name>".', $image, PolicyContext::IMAGES, $context->project);
            }

            foreach ($this->list($this->map($service['build'])['tags'] ?? []) as $tag) {
                if (1 !== preg_match($own, $this->string($tag))) {
                    $violations[] = \sprintf('its build is tagged "%s": a site\'s images are named "%s%s/<name>".', $this->string($tag), PolicyContext::IMAGES, $context->project);
                }
            }
        } elseif (str_starts_with($image, PolicyContext::IMAGES) && 1 !== preg_match($own, $image)) {
            $violations[] = \sprintf('"image: %s" is another site\'s.', $image);
        }

        return $violations;
    }

    /**
     * @param array<array-key, mixed> $build
     *
     * @return list<string>
     */
    private function build(array $build, PolicyContext $context): array
    {
        $violations = $this->unknown($build, 'build', 'build.');
        $buildContext = $this->string($build['context'] ?? '');

        if (!$context->contains($buildContext)) {
            $violations[] = \sprintf('it builds from "%s", outside the site\'s folder.', $buildContext);
        } elseif (isset($build['dockerfile']) && !$context->contains(rtrim($buildContext, '/').'/'.$this->string($build['dockerfile']))) {
            $violations[] = 'its Dockerfile is outside the site\'s folder.';
        }

        foreach (['additional_contexts' => 'reads other build contexts', 'ssh' => 'forwards SSH keys', 'secrets' => 'reads build secrets', 'entitlements' => 'asks for build entitlements', 'cache_to' => 'writes a build cache', 'cache_from' => 'reads a build cache', 'privileged' => 'builds privileged'] as $key => $what) {
            $value = $build[$key] ?? null;

            if (null !== $value && false !== $value && [] !== $value && !$this->false($value)) {
                $violations[] = \sprintf('its build %s ("%s").', $what, $key);
            }
        }

        $network = $this->string($build['network'] ?? '');

        if ('' !== $network && !\in_array($network, ['default', 'none'], true)) {
            $violations[] = \sprintf('its build uses the network "%s".', $network);
        }

        $isolation = $this->string($build['isolation'] ?? '');

        if ('' !== $isolation && 'default' !== $isolation) {
            $violations[] = 'its build asks for another isolation.';
        }

        return $violations;
    }

    /**
     * @param array<array-key, mixed> $networks
     *
     * @return list<string>
     */
    private function attachments(string $service, array $networks, PolicyContext $context): array
    {
        $violations = [];

        foreach ($networks as $network => $attachment) {
            $network = (string) $network;
            $attachment = $this->map($attachment);

            foreach ($this->unknown($attachment, 'attachment', '') as $violation) {
                $violations[] = \sprintf('on the network "%s", %s', $network, $violation);
            }

            if (!$context->isSharedNetwork($network)) {
                continue;
            }

            $allowed = $context->aliases($network, $service);

            if (null === $allowed) {
                $violations[] = \sprintf('it may not join the shared network "%s".', $network);

                continue;
            }

            foreach ($this->list($attachment['aliases'] ?? []) as $alias) {
                if (!\in_array($this->string($alias), $allowed, true)) {
                    $violations[] = \sprintf('the alias "%s" on the shared network "%s" isn\'t one of its own.', $this->string($alias), $network);
                }
            }

            foreach (['ipv4_address', 'ipv6_address', 'link_local_ips', 'driver_opts', 'mac_address'] as $fixed) {
                if (isset($attachment[$fixed]) && [] !== $attachment[$fixed] && '' !== $attachment[$fixed]) {
                    $violations[] = \sprintf('"%s" on the shared network "%s" isn\'t allowed.', $fixed, $network);
                }
            }
        }

        return $violations;
    }

    /**
     * @param array<array-key, mixed> $volume
     *
     * @return list<string>
     */
    private function volume(array $volume, PolicyContext $context): array
    {
        $violations = $this->unknown($volume, 'volume', '');

        if ($this->true($volume['external'] ?? false)) {
            $violations[] = 'external volumes aren\'t allowed: they may belong to others.';
        } elseif (!$context->ownsName($this->string($volume['name'] ?? ''))) {
            $violations[] = \sprintf('its name "%s" isn\'t in the project\'s own ("%s_…").', $this->string($volume['name'] ?? ''), $context->project);
        }

        $driver = $this->string($volume['driver'] ?? '');

        if ('' !== $driver && 'local' !== $driver) {
            $violations[] = \sprintf('the "%s" driver isn\'t allowed.', $driver);
        }

        if ([] !== $this->map($volume['driver_opts'] ?? [])) {
            $violations[] = '"driver_opts" aren\'t allowed (a local volume with them can mount host paths).';
        }

        return $violations;
    }

    /**
     * @param array<array-key, mixed> $network
     *
     * @return list<string>
     */
    private function network(string $key, array $network, PolicyContext $context): array
    {
        $name = $this->string($network['name'] ?? $key);
        $violations = $this->unknown($network, 'network', '');

        if ($this->true($network['external'] ?? false)) {
            return $context->isSharedNetwork($name) && $name === $key ? $violations : [...$violations, \sprintf('the external network "%s" isn\'t one the server shares.', $name)];
        }

        $ipam = $this->map($network['ipam'] ?? []);
        array_push($violations, ...$this->unknown($ipam, 'network.ipam', 'ipam.'));

        foreach ($this->list($ipam['config'] ?? []) as $pool) {
            array_push($violations, ...$this->unknown($this->map($pool), 'network.ipam.config', 'ipam.config.'));
        }

        $ipamDriver = $this->string($ipam['driver'] ?? '');

        if ('' !== $ipamDriver && 'default' !== $ipamDriver) {
            $violations[] = \sprintf('the IPAM driver "%s" isn\'t allowed (a plugin runs on the host).', $ipamDriver);
        }

        if ([] !== $this->map($ipam['options'] ?? [])) {
            $violations[] = '"ipam.options" aren\'t allowed.';
        }

        if ($context->isSharedNetwork($name) || !$context->ownsName($name)) {
            $violations[] = \sprintf('its name "%s" isn\'t in the project\'s own ("%s_…").', $name, $context->project);
        }

        $driver = $this->string($network['driver'] ?? '');

        if ('' !== $driver && 'bridge' !== $driver) {
            $violations[] = \sprintf('the "%s" driver isn\'t allowed.', $driver);
        }

        if ([] !== $this->map($network['driver_opts'] ?? [])) {
            $violations[] = '"driver_opts" aren\'t allowed.';
        }

        return $violations;
    }

    /**
     * The settings of a map that aren't allowed at its level (extensions, "x-…", are).
     *
     * @param array<array-key, mixed> $map
     *
     * @return list<string>
     */
    private function unknown(array $map, string $level, string $prefix): array
    {
        $violations = [];

        foreach (array_keys($map) as $key) {
            $key = (string) $key;

            if (!\in_array($key, self::NESTED_KEYS[$level], true) && !str_starts_with($key, 'x-')) {
                $violations[] = \sprintf('"%s%s" isn\'t a setting the server allows (yet).', $prefix, $key);
            }
        }

        return $violations;
    }

    /**
     * A boolean, however it's written ("true", 1, "yes": YAML and Compose take them all).
     */
    private function true(mixed $value): bool
    {
        return true === $value || 1 === $value || (\is_string($value) && \in_array(strtolower(trim($value)), ['true', '1', 'yes', 'y', 'on'], true));
    }

    private function false(mixed $value): bool
    {
        return false === $value || 0 === $value || (\is_string($value) && \in_array(strtolower(trim($value)), ['false', '0', 'no', 'n', 'off'], true));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function map(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        return \is_array($value) ? array_values($value) : (null === $value || '' === $value ? [] : [$value]);
    }

    private function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
