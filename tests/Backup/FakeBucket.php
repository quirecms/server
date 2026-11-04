<?php

declare(strict_types=1);

namespace Quire\Server\Tests\Backup;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * An S3 bucket as the server's own requests see it (path-style, one bucket), with versions, delete
 * markers and Object Lock as a provider with each setting would answer.
 */
final class FakeBucket
{
    public const string UNVERSIONED = 'unversioned';
    public const string UNLOCKED = 'unlocked';
    public const string GOVERNANCE = 'governance';
    public const string COMPLIANCE = 'compliance';

    /** @var array<string, list<array{version: string, modified: string, marker: bool, until: ?string, etag: string}>> Key => its versions, oldest first */
    public array $objects = [];

    /** @var list<array{string, string}> Method and URL of each request */
    public array $requests = [];

    private int $versions = 0;

    public function __construct(
        public string $mode = self::COMPLIANCE,
        public int $days = 30,
        public bool $mayBypass = false,
        public string $now = '2026-11-04T02:00:00Z',
    ) {
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(fn (string $method, string $url, array $options): MockResponse => $this->answer($method, $url, $options));
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $method, string $url, array $options): MockResponse
    {
        $this->requests[] = [$method, $url];
        $parts = parse_url($url);
        $path = \is_array($parts) && \is_string($parts['path'] ?? null) ? $parts['path'] : '';
        parse_str(\is_array($parts) && \is_string($parts['query'] ?? null) ? $parts['query'] : '', $query);
        $key = rawurldecode(implode('/', \array_slice(explode('/', $path), 2)));
        $version = \is_string($query['versionId'] ?? null) ? $query['versionId'] : null;
        $headers = \is_array($options['headers'] ?? null) ? implode("\n", array_map(static fn (mixed $header): string => \is_string($header) ? strtolower($header) : '', $options['headers'])) : '';

        return match (true) {
            'GET' === $method && '' === $key && \array_key_exists('versions', $query) => $this->list(\is_string($query['prefix'] ?? null) ? $query['prefix'] : ''),
            'PUT' === $method && \array_key_exists('retention', $query) => $this->locked($key, $version) ? new MockResponse('', ['http_code' => 403]) : new MockResponse('', ['http_code' => 200]),
            'GET' === $method && \array_key_exists('retention', $query) => $this->retention($key, $version),
            'PUT' === $method && str_contains($headers, 'x-amz-copy-source:') => $this->copy($key, $headers),
            'PUT' === $method => $this->put($key, \is_string($options['body'] ?? null) ? $options['body'] : ''),
            'DELETE' === $method && null !== $version => $this->locked($key, $version) && !($this->mayBypass && self::GOVERNANCE === $this->mode && str_contains($headers, 'x-amz-bypass-governance-retention: true')) ? new MockResponse('', ['http_code' => 403]) : $this->remove($key, $version),
            'DELETE' === $method => $this->marker($key),
            default => new MockResponse('', ['http_code' => 501]),
        };
    }

    private function put(string $key, string $body, ?string $etag = null): MockResponse
    {
        $version = $this->version();
        $locked = \in_array($this->mode, [self::GOVERNANCE, self::COMPLIANCE], true);
        $this->objects[$key][] = ['version' => $version, 'modified' => $this->now, 'marker' => false, 'until' => $locked ? new \DateTimeImmutable($this->now)->modify('+'.$this->days.' days')->format('Y-m-d\TH:i:s\Z') : null, 'etag' => $etag ?? md5($body)];

        return new MockResponse('', ['http_code' => 200, 'response_headers' => self::UNVERSIONED === $this->mode ? [] : ['x-amz-version-id' => $version]]);
    }

    private function copy(string $key, string $headers): MockResponse
    {
        preg_match('/versionid=([^\s&]+)/', $headers, $match);

        $source = array_find($this->objects[$key] ?? [], static fn (array $entry): bool => strtolower($entry['version']) === ($match[1] ?? ''));

        return null === $source ? new MockResponse('', ['http_code' => 404]) : $this->put($key, '', $source['etag']);
    }

    private function marker(string $key): MockResponse
    {
        $this->objects[$key][] = ['version' => $this->version(), 'modified' => $this->now, 'marker' => true, 'until' => null, 'etag' => ''];

        return new MockResponse('', ['http_code' => 204]);
    }

    private function remove(string $key, string $version): MockResponse
    {
        $this->objects[$key] = array_values(array_filter($this->objects[$key] ?? [], static fn (array $entry): bool => $entry['version'] !== $version));

        return new MockResponse('', ['http_code' => 204]);
    }

    private function locked(string $key, ?string $version): bool
    {
        $entry = array_find($this->objects[$key] ?? [], static fn (array $entry): bool => $entry['version'] === $version);

        return null !== $entry && null !== $entry['until'] && $entry['until'] > $this->now;
    }

    private function retention(string $key, ?string $version): MockResponse
    {
        $entry = array_find($this->objects[$key] ?? [], static fn (array $entry): bool => $entry['version'] === $version);

        if (null === $entry || null === $entry['until']) {
            return new MockResponse('<Error><Code>NoSuchObjectLockConfiguration</Code></Error>', ['http_code' => 404]);
        }

        return new MockResponse(\sprintf('<Retention><Mode>%s</Mode><RetainUntilDate>%s</RetainUntilDate></Retention>', strtoupper($this->mode), $entry['until']), ['http_code' => 200]);
    }

    private function list(string $prefix): MockResponse
    {
        $xml = '<ListVersionsResult><IsTruncated>false</IsTruncated>';

        foreach ($this->objects as $key => $entries) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }

            foreach ($entries as $i => $entry) {
                $latest = $i === \count($entries) - 1 ? 'true' : 'false';
                $xml .= \sprintf('<%1$s><Key>%2$s</Key><VersionId>%3$s</VersionId><IsLatest>%4$s</IsLatest><LastModified>%5$s</LastModified><ETag>"%6$s"</ETag></%1$s>', $entry['marker'] ? 'DeleteMarker' : 'Version', htmlspecialchars($key, \ENT_XML1), $entry['version'], $latest, $entry['modified'], $entry['etag']);
            }
        }

        return new MockResponse($xml.'</ListVersionsResult>', ['http_code' => 200]);
    }

    private function version(): string
    {
        return 'v'.++$this->versions;
    }
}
