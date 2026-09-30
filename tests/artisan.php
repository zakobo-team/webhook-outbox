<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Application as Testbench;
use Spatie\WebhookServer\WebhookServerServiceProvider;
use Symfony\Component\Console\Input\ArgvInput;
use Zakobo\WebhookOutbox\WebhookOutboxServiceProvider;

/*
 * Entry point for the child processes spawned by Laravel's Concurrency process driver. The `testbench` CLI is not
 * usable here: every start copies testbench.yaml into the skeleton and restores a backup on exit, so two children
 * starting together race on that file and crash.
 */
require __DIR__.'/../vendor/autoload.php';

$app = Testbench::create(
    basePath: Testbench::applicationBasePath(),
    options: ['extra' => ['providers' => [WebhookServerServiceProvider::class, WebhookOutboxServiceProvider::class]]],
);

exit($app->handleCommand(new ArgvInput));
