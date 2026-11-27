# Changelog

## 0.4.0

- Automatic retries for 429, 502, 503 and 504 (3 attempts by default) with exponential backoff and jitter; `Retry-After` is honored.
- Mutating calls reuse the same idempotency key on every attempt.
- New `RateLimitException` (extends `ApiException`) with `retryAfter`, `limit` and `remaining`. `ApiException` is no longer `final`.
- New `Client` constructor arguments `maxAttempts`, `sleep`, `retryBaseDelay`, `retryMaxDelay` (appended, backward compatible).
- The custom `transport` callable may return a `headers` array.
- OpenAPI copy updated with 429 responses.

## 0.3.1

- Public domain search and spec aligned with the live API.
