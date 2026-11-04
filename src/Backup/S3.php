<?php

declare(strict_types=1);

namespace Quire\Server\Backup;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The few S3 requests the server makes itself, beside restic's (D-37): to check the bucket keeps what's
 * deleted, and to bring it back. Signed with AWS's Signature Version 4, path-style (every provider
 * takes it), on the repository's bucket and folder only.
 *
 * @phpstan-type Version array{key: string, version: string, latest: bool, modified: \DateTimeImmutable, marker: bool, etag: string}
 */
final readonly class S3
{
    private const string SERVICE = 's3';

    /**
     * @param \Closure(): \DateTimeImmutable|null $now
     */
    public function __construct(
        private HttpClientInterface $client,
        private string $endpoint,
        public string $bucket,
        public string $prefix,
        private string $accessKey,
        private string $secretKey,
        private string $region,
        private ?\Closure $now = null,
    ) {
    }

    /**
     * The bucket and folder of the repository (s3:https://host/bucket/folder), with its keys, or others.
     */
    public static function of(BackupSettings $settings, HttpClientInterface $client, ?string $accessKey = null, ?string $secretKey = null): self
    {
        if (1 !== preg_match('#^s3:(https?://[^/]+)/([^/]+)(?:/(.+))?\z#', $settings->repository, $match)) {
            throw new \InvalidArgumentException('QUIRE_SERVER_BACKUP_REPOSITORY isn\'t an S3 bucket.');
        }

        return new self($client, $match[1], $match[2], rtrim($match[3] ?? '', '/'), $accessKey ?? $settings->accessKey, $secretKey ?? $settings->secretKey, $settings->region);
    }

    /** A key in the repository's folder */
    public function key(string $name): string
    {
        return ('' === $this->prefix ? '' : $this->prefix.'/').$name;
    }

    /**
     * @return array{status: int, version: ?string}
     */
    public function put(string $key, string $body): array
    {
        // A bucket with Object Lock takes a write only with its checksum
        $response = $this->request('PUT', $key, [], ['content-md5' => base64_encode(md5($body, true))], $body);

        return ['status' => $response['status'], 'version' => $response['headers']['x-amz-version-id'] ?? null];
    }

    /**
     * Deletes one version of an object for good (what a lock refuses), or a delete marker.
     */
    public function deleteVersion(string $key, string $version, bool $bypassGovernance = false): int
    {
        return $this->request('DELETE', $key, ['versionId' => $version], $bypassGovernance ? ['x-amz-bypass-governance-retention' => 'true'] : [])['status'];
    }

    /**
     * Sets a version's retention (shortening it is what a lock in compliance mode refuses).
     */
    public function retain(string $key, string $version, string $mode, \DateTimeImmutable $until): int
    {
        $body = \sprintf('<Retention xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Mode>%s</Mode><RetainUntilDate>%s</RetainUntilDate></Retention>', htmlspecialchars($mode, \ENT_XML1), $until->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));

        return $this->request('PUT', $key, ['retention' => '', 'versionId' => $version], ['content-md5' => base64_encode(md5($body, true))], $body)['status'];
    }

    /**
     * A version's retention, or null when it has none.
     *
     * @return array{mode: string, until: \DateTimeImmutable}|null
     */
    public function retention(string $key, string $version): ?array
    {
        $response = $this->request('GET', $key, ['retention' => '', 'versionId' => $version]);
        $xml = 200 === $response['status'] ? $this->xml($response['body']) : null;

        if (!$xml instanceof \SimpleXMLElement || '' === (string) $xml->Mode || '' === (string) $xml->RetainUntilDate) {
            return null;
        }

        return ['mode' => (string) $xml->Mode, 'until' => new \DateTimeImmutable((string) $xml->RetainUntilDate)];
    }

    /**
     * Every version and delete marker of the objects under a prefix, page by page.
     *
     * @return list<Version>
     */
    public function versions(string $prefix): array
    {
        $all = [];
        $marker = ['key-marker' => '', 'version-id-marker' => ''];

        do {
            $query = ['versions' => '', 'prefix' => $prefix, ...array_filter($marker, static fn (string $value): bool => '' !== $value)];
            $response = $this->request('GET', '', $query);
            $xml = 200 === $response['status'] ? $this->xml($response['body']) : null;

            if (!$xml instanceof \SimpleXMLElement) {
                throw new BackupFailed(\sprintf('The bucket didn\'t list its versions (HTTP %d): the key needs the right to (s3:ListBucketVersions).', $response['status']));
            }

            foreach ([...iterator_to_array($xml->Version, false), ...iterator_to_array($xml->DeleteMarker, false)] as $entry) {
                $all[] = ['key' => (string) $entry->Key, 'version' => (string) $entry->VersionId, 'latest' => 'true' === (string) $entry->IsLatest, 'modified' => new \DateTimeImmutable((string) $entry->LastModified), 'marker' => 'DeleteMarker' === $entry->getName(), 'etag' => trim((string) $entry->ETag, '"')];
            }

            $truncated = 'true' === (string) $xml->IsTruncated;
            $marker = ['key-marker' => (string) $xml->NextKeyMarker, 'version-id-marker' => (string) $xml->NextVersionIdMarker];
        } while ($truncated);

        return $all;
    }

    /**
     * Makes an older version the object's current one again (a copy of it).
     */
    public function restoreVersion(string $key, string $version): int
    {
        return $this->request('PUT', $key, [], ['x-amz-copy-source' => '/'.$this->bucket.'/'.$this->path($key).'?versionId='.rawurlencode($version)])['status'];
    }

    /**
     * The headers that sign a request (AWS Signature Version 4), with the payload's hash and the date.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers Lowercase names
     *
     * @return array<string, string>
     */
    public function sign(string $method, string $path, array $query, array $headers, string $body, \DateTimeImmutable $now, string $service = self::SERVICE): array
    {
        $date = $now->setTimezone(new \DateTimeZone('UTC'));
        $headers = [...$headers, 'host' => parse_url($this->endpoint, \PHP_URL_HOST).(null === parse_url($this->endpoint, \PHP_URL_PORT) ? '' : ':'.parse_url($this->endpoint, \PHP_URL_PORT)), 'x-amz-date' => $date->format('Ymd\THis\Z')];

        if (self::SERVICE === $service) {
            $headers['x-amz-content-sha256'] = hash('sha256', $body);
        }

        ksort($headers, \SORT_STRING);
        ksort($query, \SORT_STRING);
        $canonicalQuery = implode('&', array_map(static fn (string $name, string $value): string => rawurlencode($name).'='.rawurlencode($value), array_keys($query), $query));
        $canonicalHeaders = implode('', array_map(static fn (string $name, string $value): string => $name.':'.trim($value)."\n", array_keys($headers), $headers));
        $signedHeaders = implode(';', array_keys($headers));
        $scope = $date->format('Ymd').'/'.$this->region.'/'.$service.'/aws4_request';
        $request = implode("\n", [$method, $path, $canonicalQuery, $canonicalHeaders, $signedHeaders, hash('sha256', $body)]);
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $date->format('Ymd\THis\Z'), $scope, hash('sha256', $request)]);
        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', $service, hash_hmac('sha256', $this->region, hash_hmac('sha256', $date->format('Ymd'), 'AWS4'.$this->secretKey, true), true), true), true);
        $headers['authorization'] = \sprintf('AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s', $this->accessKey, $scope, $signedHeaders, hash_hmac('sha256', $toSign, $key));

        return $headers;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(string $method, string $key, array $query, array $headers = [], string $body = ''): array
    {
        $path = '/'.rawurlencode($this->bucket).('' === $key ? '' : '/'.$this->path($key));
        $signed = $this->sign($method, $path, $query, $headers, $body, $this->now instanceof \Closure ? ($this->now)() : new \DateTimeImmutable());
        unset($signed['host']);
        $url = $this->endpoint.$path.([] === $query ? '' : '?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986));

        try {
            $response = $this->client->request($method, $url, ['headers' => $signed, 'body' => $body, 'max_redirects' => 0, 'timeout' => 30]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
            $received = array_map(static fn (array $values): string => $values[0] ?? '', $response->getHeaders(false));
        } catch (ExceptionInterface $e) {
            throw new BackupFailed(\sprintf('The bucket can\'t be reached: %s', $e->getMessage()), 0, $e);
        }

        return ['status' => $status, 'headers' => $received, 'body' => $content];
    }

    /** A key, each part of it encoded as SigV4 does */
    private function path(string $key): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $key)));
    }

    private function xml(string $body): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body, \SimpleXMLElement::class, \LIBXML_NONET | \LIBXML_NOCDATA);

            return false === $xml ? null : $xml;
        } finally {
            libxml_use_internal_errors($previous);
        }
    }
}
