<?php

use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

$queueConnection = $argv[1] ?? '';
if (! in_array($queueConnection, ['database', 'ksef_submit'], true)) {
    fwrite(STDERR, "[queue-start] Nieobslugiwane polaczenie kolejki.\n");
    exit(78);
}

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';

    // Load Laravel's effective configuration without booting the scheduler or application providers.
    $app->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);
    $app->register(DatabaseServiceProvider::class);
    $config = $app['config'];
    $queue = $config->get('queue.connections.'.$queueConnection);
    if (($queue['driver'] ?? null) !== 'database') {
        throw new RuntimeException('Expected a database queue.');
    }

    $default = $config->get('database.default');
    $connections = [$queue['connection'] ?? $default];
    $cache = $config->get('cache.stores.'.$config->get('cache.default'));
    if (($cache['driver'] ?? null) === 'database') {
        $connections[] = $cache['connection'] ?? $default;
    }

    foreach (array_unique($connections) as $name) {
        $database = $app['db']->connection($name);
        if ($database->getDriverName() !== 'mysql') {
            throw new RuntimeException('Expected a MySQL connection.');
        }
        $database->selectOne('SELECT 1', [], false);
    }
} catch (Throwable $exception) {
    $mysqlCode = 0;
    for ($error = $exception; $error !== null; $error = $error->getPrevious()) {
        if ($error instanceof PDOException && isset($error->errorInfo[1])) {
            $mysqlCode = (int) $error->errorInfo[1];
            break;
        }
    }

    if (in_array($mysqlCode, [1040, 1053, 2002, 2003, 2006, 2013], true)) {
        exit(75);
    }

    $detail = $mysqlCode ? " (kod MySQL: {$mysqlCode})" : '';
    fwrite(STDERR, "[queue-start] Blad konfiguracji lub trwaly blad sprawdzenia MySQL{$detail}.\n");
    exit(78);
}
