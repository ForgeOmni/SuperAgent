<?php

require __DIR__ . '/../vendor/autoload.php';

// Prevent tests from accidentally launching the user's browser via Auth/DeviceCodeFlow.
// Honoured by DeviceCodeFlow::tryOpenBrowser().
putenv('PHPUNIT_RUNNING=1');
putenv('SUPERAGENT_NO_BROWSER=1');

// Load .env file if it exists
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            putenv(trim($line));
        }
    }
}
