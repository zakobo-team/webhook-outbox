<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/*
 * Laravel's Concurrency process driver spawns `php {ARTISAN_BINARY} invoke-serialized-closure` from the
 * application's base path. Under Testbench that path is the skeleton, so the child must be the `testbench`
 * binary pointed back at this package's testbench.yaml (providers and environment). Symfony's Process only
 * inherits variables present in $_SERVER/$_ENV, so a bare putenv() would not reach the child.
 */
define('ARTISAN_BINARY', __DIR__.'/../vendor/bin/testbench');

$packagePath = realpath(__DIR__.'/..');
putenv('TESTBENCH_WORKING_PATH='.$packagePath);
$_ENV['TESTBENCH_WORKING_PATH'] = $_SERVER['TESTBENCH_WORKING_PATH'] = $packagePath;
