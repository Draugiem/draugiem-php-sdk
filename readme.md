### Draugiem.lv API library for PHP

[![Latest Stable Version](https://poser.pugx.org/draugiem/draugiem-php-sdk/v/stable.svg)](https://packagist.org/packages/draugiem/draugiem-php-sdk)
[![Latest Unstable Version](https://poser.pugx.org/draugiem/draugiem-php-sdk/v/unstable.svg)](https://packagist.org/packages/draugiem/draugiem-php-sdk)
[![License](https://poser.pugx.org/draugiem/draugiem-php-sdk/license.svg)](https://packagist.org/packages/draugiem/draugiem-php-sdk)

To make application development faster and easier, we've created a PHP library that performs API calls and automatically converts the requested data to the PHP data structures.
PHP library can be used both for integrated applications and draugiem.lv Passport applications.

The library requires PHP5 environment and uses PHP session mechanism for storing user data during sessions.
To be able to perform API calls, PHP configuration you have to enable access to HTTP URLs for file_get_contents function (enable allow_url_fopen setting in PHP configuration).

In order to use PHP library, you have to include file DraugiemApi.php in your application.

Usage
-----

Check [examples] for more on how to use the API library.

```php
require 'DraugiemApi.php';

$draugiem = new DraugiemApi( 'YOUR_APP_ID', 'YOUR_APP_KEY' );

session_start();
$draugiem->cookieFix(); // Iframe cookie workaround for IE and Safari

$session = $draugiem->getSession();
if ($session) {
	$user = $draugiem->getUserData(); //Get user info
}
```

Usage with composer
----

1. Add this package to your composer.json

    ```php
    "draugiem/draugiem-php-sdk": "1.*"
    ```

2. Update composer.json packages

    ```
    composer update
    ```

[examples]: /examples/test_application.php
