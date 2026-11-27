<?php

declare(strict_types=1);

namespace KmerHosting;

use JsonException;
use RuntimeException;

final class Client
{
    private string $apiKey;
    private string $baseUrl;
    private string $domainSearchUrl;
    /** @var callable|null */
    private $transport;
    /** @var callable */
    private $sleep;
    private int $maxAttempts;

    /**
     * @param int $maxAttempts Total tries for 429/502/503/504 responses (1 disables retries).
     * @param callable|null $sleep Receives the wait in seconds (float); defaults to usleep.
     */
    public function __construct(?string $apiKey = null, string $baseUrl = 'https://api.kmerhosting.com', private readonly int $timeout = 30, ?callable $transport = null, string $domainSearchUrl = 'https://domain.kmerhosting.com', int $maxAttempts = 3, ?callable $sleep = null, private readonly float $retryBaseDelay = 0.5, private readonly float $retryMaxDelay = 60.0)
    {
        $this->maxAttempts = max(1, $maxAttempts);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
        $this->apiKey = $apiKey ?: (getenv('KMERHOSTING_API_KEY') ?: '');
        if ($this->apiKey === '') {
            throw new RuntimeException('Set KMERHOSTING_API_KEY or pass an API key to Client.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->domainSearchUrl = rtrim($domainSearchUrl, '/');
        $this->transport = $transport;
    }

    public function account(): AccountResource
    {
        return new AccountResource($this);
    }

    public function services(): ServicesResource
    {
        return new ServicesResource($this);
    }

    public function domains(): DomainsResource
    {
        return new DomainsResource($this);
    }

    public function email(): EmailHostingResource
    {
        return new EmailHostingResource($this);
    }

    public function hosting(): SharedHostingResource
    {
        return new SharedHostingResource($this);
    }

    public function lxc(): LxcResource
    {
        return new LxcResource($this);
    }

    public function kvm(): KvmResource
    {
        return new KvmResource($this);
    }

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * Public request without the API key, on the domain search service.
     * A custom transport receives the absolute URL as its path.
     * @return array<string, mixed>
     */
    public function publicDomainSearch(string $pathAndQuery): array
    {
        return $this->request('GET', $pathAndQuery, null, null, $this->domainSearchUrl, false);
    }

    /** @param array<string, mixed>|null $body @return array<string, mixed> */
    public function mutate(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request($method, $path, $body, $idempotencyKey ?: $this->idempotencyKey());
    }

    /** @param array<string, mixed>|null $body @return array<string, mixed> */
    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null, ?string $baseUrl = null, bool $authenticated = true): array
    {
        $headers = ['Accept: application/json'];
        if ($authenticated) {
            $headers[] = "Authorization: Bearer {$this->apiKey}";
        }
        if ($idempotencyKey !== null) {
            $headers[] = "Idempotency-Key: {$idempotencyKey}";
        }

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        for ($attempt = 1;; $attempt++) {
            // Same headers (including Idempotency-Key) and body on every attempt.
            [$status, $payload, $responseHeaders] = $this->perform($method, $path, $body, $headers, $idempotencyKey, $baseUrl);
            if ($status >= 200 && $status < 300) {
                return $payload;
            }
            if (in_array($status, [429, 502, 503, 504], true) && $attempt < $this->maxAttempts) {
                ($this->sleep)($this->retryDelay($attempt, $this->retryAfter($responseHeaders)));
                continue;
            }
            throw $this->errorFor($status, $payload, $responseHeaders);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @param list<string> $headers
     * @return array{0: int, 1: array<string, mixed>, 2: array<string, string>}
     */
    private function perform(string $method, string $path, ?array $body, array $headers, ?string $idempotencyKey, ?string $baseUrl): array
    {
        if ($this->transport !== null) {
            $response = ($this->transport)($method, $baseUrl === null ? $path : $baseUrl . $path, $body, $headers, $idempotencyKey);
            $payload = is_array($response['body'] ?? null) ? $response['body'] : ['data' => $response['body'] ?? null];
            $responseHeaders = [];
            foreach ((array) ($response['headers'] ?? []) as $name => $value) {
                $responseHeaders[strtolower((string) $name)] = (string) $value;
            }
            return [(int) ($response['status'] ?? 200), $payload, $responseHeaders];
        }

        $curl = curl_init(($baseUrl ?? $this->baseUrl) . $path);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize the HTTP client.');
        }

        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min($this->timeout, 10),
            CURLOPT_FAILONERROR => false,
            CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        $raw = curl_exec($curl);
        if ($raw === false) {
            $message = curl_error($curl) ?: 'Unable to reach the KmerHosting API.';
            curl_close($curl);
            throw new RuntimeException($message);
        }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [$status, $this->decode($raw), $responseHeaders];
    }

    /** @param array<string, string> $headers */
    private function retryAfter(array $headers): ?float
    {
        $value = $headers['retry-after'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        $time = strtotime($value);
        return $time === false ? null : max(0.0, (float) ($time - time()));
    }

    private function retryDelay(int $attempt, ?float $retryAfter): float
    {
        if ($retryAfter !== null) {
            $delay = $retryAfter + mt_rand(0, 250) / 1000;
        } else {
            $delay = mt_rand(0, 1000) / 1000 * min($this->retryMaxDelay, $this->retryBaseDelay * (2 ** ($attempt - 1)));
        }
        return min($this->retryMaxDelay, $delay);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    private function errorFor(int $status, array $payload, array $headers): ApiException
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        if (!is_array($payload['error'] ?? null) && is_string($payload['error'] ?? null)) {
            // The public domain-search service answers {"error": "<code>", "message": "..."}.
            $error = ['code' => $payload['error'], 'message' => $payload['message'] ?? null];
        }
        $requestId = isset($error['request_id']) ? (string) $error['request_id'] : ($headers['x-request-id'] ?? null);
        if ($status === 429) {
            return new RateLimitException(
                (string) ($error['message'] ?? 'KmerHosting API rate limit exceeded.'),
                (string) ($error['code'] ?? 'rate_limit_exceeded'),
                $requestId,
                $payload,
                $this->retryAfter($headers),
                isset($headers['x-ratelimit-limit']) ? (int) $headers['x-ratelimit-limit'] : null,
                isset($headers['x-ratelimit-remaining']) ? (int) $headers['x-ratelimit-remaining'] : null,
            );
        }
        return new ApiException(
            (string) ($error['message'] ?? "KmerHosting API request failed with status {$status}."),
            $status,
            (string) ($error['code'] ?? 'request_failed'),
            $requestId,
            $payload,
        );
    }

    /** @return array<string, mixed> */
    private function decode(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : ['data' => $decoded];
        } catch (JsonException) {
            return ['data' => $raw];
        }
    }

    private function idempotencyKey(): string
    {
        return bin2hex(random_bytes(16));
    }
}

abstract class Resource
{
    public function __construct(protected readonly Client $client)
    {
    }

    protected function id(string $value): string
    {
        return rawurlencode($value);
    }
}

final class AccountResource extends Resource
{
    /** @return array<string, mixed> */
    public function get(): array
    {
        return $this->client->get('/v1/account');
    }

    /** @return array<string, mixed> */
    public function apiUsage(): array
    {
        return $this->client->get('/v1/account/api-usage');
    }
}

final class ServicesResource extends Resource
{
    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->client->get('/v1/services');
    }

    /** @return array<string, mixed> */
    public function get(string $serviceId): array
    {
        return $this->client->get('/v1/services/' . $this->id($serviceId));
    }
}

final class DomainsResource extends Resource
{
    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->client->get('/v1/domains');
    }

    /** @return array<string, mixed> */
    public function get(string $domainId): array
    {
        return $this->client->get('/v1/domains/' . $this->id($domainId));
    }

    public function dns(): DnsResource
    {
        return new DnsResource($this->client);
    }

    /** @return array<string, mixed> */
    public function setAutoRenew(string $domainId, bool $enabled, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('PUT', '/v1/domains/' . $this->id($domainId) . '/auto-renew', ['enabled' => $enabled], $idempotencyKey);
    }

    /** @param list<string> $nameServers @return array<string, mixed> */
    public function setNameservers(string $domainId, array $nameServers, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('PUT', '/v1/domains/' . $this->id($domainId) . '/nameservers', ['nameServers' => $nameServers], $idempotencyKey);
    }

    /**
     * Check availability and price of 1 to 20 domains. Public endpoint on domain.kmerhosting.com:
     * no API key is sent; limited to 20 requests per IP per minute (a bulk search counts once).
     * @param string|list<string> $domains
     * @return array<string, mixed> ['results' => [...]]
     */
    public function search(string|array $domains): array
    {
        $list = array_values(array_filter(array_map('trim', is_array($domains) ? $domains : [$domains]), static fn (string $d): bool => $d !== ''));
        if (count($list) < 1 || count($list) > 20) {
            throw new \InvalidArgumentException('Pass between 1 and 20 domains to domains()->search().');
        }
        return $this->client->publicDomainSearch('/api/domain-search?' . http_build_query(['domains' => implode(',', $list)]));
    }
}

final class DnsResource extends Resource
{
    /** @return array<string, mixed> */
    public function all(string $domainId): array
    {
        return $this->client->get('/v1/domains/' . $this->id($domainId) . '/dns');
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    public function create(string $domainId, array $record, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/domains/' . $this->id($domainId) . '/dns', $record, $idempotencyKey);
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    public function update(string $domainId, string $recordId, array $record, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('PUT', '/v1/domains/' . $this->id($domainId) . '/dns/' . $this->id($recordId), $record, $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function delete(string $domainId, string $recordId, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('DELETE', '/v1/domains/' . $this->id($domainId) . '/dns/' . $this->id($recordId), null, $idempotencyKey);
    }
}

final class EmailHostingResource extends Resource
{
    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->client->get('/v1/email/services');
    }

    /** @return array<string, mixed> */
    public function provision(string $serviceId, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/email/services/' . $this->id($serviceId) . '/provision', [], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function syncDns(string $serviceId, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/email/services/' . $this->id($serviceId) . '/dns/sync', [], $idempotencyKey);
    }
}

final class SharedHostingResource extends Resource
{
    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->client->get('/v1/hosting/services');
    }

    /** @return array<string, mixed> */
    public function stats(string $serviceId): array
    {
        return $this->client->get('/v1/hosting/services/' . $this->id($serviceId) . '/stats');
    }

    /** @return array<string, mixed> */
    public function createPanelAccess(string $serviceId, string $target = 'panel', ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/hosting/services/' . $this->id($serviceId) . '/panel-access', ['target' => $target], $idempotencyKey);
    }
}

final class LxcResource extends Resource
{
    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->client->get('/v1/lxc/instances');
    }

    /** @return array<string, mixed> */
    public function get(string $serviceId): array
    {
        return $this->client->get('/v1/lxc/instances/' . $this->id($serviceId));
    }

    public function metrics(string $serviceId): array
    {
        return $this->client->get('/v1/lxc/instances/' . $this->id($serviceId) . '/metrics');
    }

    public function action(string $serviceId, string $action, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/lxc/instances/' . $this->id($serviceId) . '/actions', ['action' => $action], $idempotencyKey);
    }

    public function listSnapshots(string $serviceId): array
    {
        return $this->client->get('/v1/lxc/instances/' . $this->id($serviceId) . '/snapshots');
    }

    public function snapshot(string $serviceId, string $action, string $name, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/lxc/instances/' . $this->id($serviceId) . '/snapshots', ['action' => $action, 'name' => $name], $idempotencyKey);
    }

    public function changePassword(string $serviceId, string $password, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/lxc/instances/' . $this->id($serviceId) . '/password', ['password' => $password], $idempotencyKey);
    }

    public function reinstall(string $serviceId, string $distribution, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/lxc/instances/' . $this->id($serviceId) . '/reinstall', ['distribution' => $distribution], $idempotencyKey);
    }

    public function createTerminalTicket(string $serviceId, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/lxc/instances/' . $this->id($serviceId) . '/terminal-ticket', [], $idempotencyKey);
    }

    public function setAutoRenew(string $serviceId, bool $enabled, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('PUT', '/v1/lxc/instances/' . $this->id($serviceId) . '/auto-renew', ['enabled' => $enabled], $idempotencyKey);
    }

    public function setBillingPeriod(string $serviceId, int $billingMonths, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('PUT', '/v1/lxc/instances/' . $this->id($serviceId) . '/billing-period', ['billingMonths' => $billingMonths], $idempotencyKey);
    }
}

final class KvmResource extends Resource
{
    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->client->get('/v1/kvm/instances');
    }

    /** @return array<string, mixed> */
    public function get(string $serviceId): array
    {
        return $this->client->get('/v1/kvm/instances/' . $this->id($serviceId));
    }

    /** @return array<string, mixed> */
    public function action(string $serviceId, string $action, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/kvm/instances/' . $this->id($serviceId) . '/actions', ['action' => $action], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function setAutoRenew(string $serviceId, bool $enabled, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('PUT', '/v1/kvm/instances/' . $this->id($serviceId) . '/auto-renew', ['enabled' => $enabled], $idempotencyKey);
    }

    public function resetPassword(string $serviceId, string $password, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/kvm/instances/' . $this->id($serviceId) . '/password', ['password' => $password], $idempotencyKey);
    }

    public function renew(string $serviceId, ?int $billingMonths = null, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/kvm/instances/' . $this->id($serviceId) . '/renew', $billingMonths === null ? [] : ['billingMonths' => $billingMonths], $idempotencyKey);
    }

    public function cancel(string $serviceId, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/kvm/instances/' . $this->id($serviceId) . '/cancel', [], $idempotencyKey);
    }

    public function keepService(string $serviceId, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/kvm/instances/' . $this->id($serviceId) . '/keep-service', [], $idempotencyKey);
    }

    public function snapshots(): KvmSnapshotsResource
    {
        return new KvmSnapshotsResource($this->client);
    }
}

final class KvmSnapshotsResource extends Resource
{
    /** @return array<string, mixed> */
    public function all(string $serviceId): array
    {
        return $this->client->get('/v1/kvm/instances/' . $this->id($serviceId) . '/snapshots');
    }

    /** @param array{name: string, description?: string} $snapshot @return array<string, mixed> */
    public function create(string $serviceId, array $snapshot, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/kvm/instances/' . $this->id($serviceId) . '/snapshots', $snapshot, $idempotencyKey);
    }

    /** @param array{name?: string, description?: string} $snapshot @return array<string, mixed> */
    public function update(string $serviceId, string $snapshotId, array $snapshot, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('PATCH', '/v1/kvm/instances/' . $this->id($serviceId) . '/snapshots/' . $this->id($snapshotId), $snapshot, $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function delete(string $serviceId, string $snapshotId, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('DELETE', '/v1/kvm/instances/' . $this->id($serviceId) . '/snapshots/' . $this->id($snapshotId), null, $idempotencyKey);
    }

    public function rollback(string $serviceId, string $snapshotId, ?string $idempotencyKey = null): array
    {
        return $this->client->mutate('POST', '/v1/kvm/instances/' . $this->id($serviceId) . '/snapshots/rollback', ['snapshotId' => $snapshotId], $idempotencyKey);
    }
}
