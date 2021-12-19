<?php
$config = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// The target of php8 would require to replace resource in MultiHttpClient.php
// But the classes CurlHandle and CurlMultiHandle not exists in php7 to be replaced with.
$config['target_php_version'] = '7.4';

return $config;
