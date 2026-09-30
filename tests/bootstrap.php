<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/*
 * Laravel's Concurrency process driver spawns `php {ARTISAN_BINARY} invoke-serialized-closure`. Under Testbench the
 * default `artisan` is the skeleton's, which knows nothing about this package, so children boot through
 * tests/artisan.php.
 */
define('ARTISAN_BINARY', __DIR__.'/artisan.php');
