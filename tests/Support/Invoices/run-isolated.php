<?php

// Run the existing test runner with private storage, logs, temp files and runner cache.
require dirname(__DIR__, 3).'/vendor/autoload.php';

use Tests\Support\Invoices\ExportTestWorkspace;

$workspace = new ExportTestWorkspace;
$workspace->activate();
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'single'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$exit = 1;
$previousScanDirectory = getenv('PHP_INI_SCAN_DIR');
try {
    // Artisan launches PHPUnit in another PHP process; a private scanned INI reaches both.
    $iniDirectory = $workspace->root.'/ini';
    mkdir($iniDirectory, 0700);
    file_put_contents($iniDirectory.'/export-tests.ini',
        'sys_temp_dir="'.str_replace('\\', '/', $workspace->root)."/scratch\"\n"
        ."allow_url_fopen=1\n"
        .'auto_prepend_file="'.str_replace('\\', '/', __DIR__)."/block-network.php\"\n"
        ."disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail\n");
    putenv('PHP_INI_SCAN_DIR='.($previousScanDirectory === false ? '' : $previousScanDirectory).PATH_SEPARATOR.$iniDirectory);
    $arguments = array_slice($argv, 1);
    if (($arguments[0] ?? null) === '--wait-for-start') {
        array_shift($arguments);
        fwrite(STDERR, "READY\n");
        if (trim(fgets(STDIN) ?: '') !== 'START') {
            throw new RuntimeException('Missing parallel test start signal.');
        }
    }
    $command = [PHP_BINARY, '-d', 'sys_temp_dir='.$workspace->root.'/scratch', 'artisan', 'test', '--compact',
        '--do-not-cache-result', '--cache-directory='.$workspace->root.'/cache/phpunit', ...$arguments];
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, dirname(__DIR__, 3));
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start isolated PHP test process.');
    }
    $exit = proc_close($process);
    if ($workspace->exportFiles() !== []) {
        fwrite(STDERR, "Run-level export files leaked before cleanup.\n");
        $exit = 1;
    }
} finally {
    putenv($previousScanDirectory === false ? 'PHP_INI_SCAN_DIR' : 'PHP_INI_SCAN_DIR='.$previousScanDirectory);
    $workspace->restore();
    $workspace->remove();
}
exit($exit);
