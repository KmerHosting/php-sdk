# kmerhosting/sdk

```bash
composer require kmerhosting/sdk
```

```php
use KmerHosting\Client;

$client = new Client(); // reads KMERHOSTING_API_KEY
$services = $client->services()->all();
```

Use this package only from trusted server-side PHP code.

Source: https://github.com/KmerHosting/php-sdk
API documentation: https://api.kmerhosting.com/docs

License: Apache-2.0.
