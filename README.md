# kmerhosting/sdk

```bash
composer require kmerhosting/sdk
```

```php
use KmerHosting\Client;

$client = new Client(); // reads KMERHOSTING_API_KEY
$services = $client->services()->all();
```

Check domain availability and price (public, no API key used, up to 20 domains, 20 searches per minute per IP):

```php
$results = $client->domains()->search(['example.com', 'example.cm'])['results'];
```

## Retries and rate limits

Requests that fail with `429`, `502`, `503` or `504` are retried automatically (3 attempts in total) with exponential backoff and jitter. `Retry-After` is honored, and a write keeps the **same** idempotency key on every attempt. When attempts run out on a 429 the client throws `RateLimitException` (a subclass of `ApiException`):

```php
use KmerHosting\ApiException;
use KmerHosting\RateLimitException;

try {
    $client->services()->all();
} catch (RateLimitException $e) {
    echo "retry in {$e->retryAfter}s";
} catch (ApiException $e) {
    // other API errors
}

// Tune or disable: the 6th argument is the total number of attempts (1 disables retries).
$client = new Client(null, 'https://api.kmerhosting.com', 30, null, 'https://domain.kmerhosting.com', 5);
```

Use this package only from trusted server-side PHP code.

Source: https://github.com/KmerHosting/php-sdk
API documentation: https://api.kmerhosting.com/docs

License: Apache-2.0.
