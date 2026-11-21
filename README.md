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

Use this package only from trusted server-side PHP code.

Source: https://github.com/KmerHosting/php-sdk
API documentation: https://api.kmerhosting.com/docs

License: Apache-2.0.
