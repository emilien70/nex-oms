<?php

namespace Tests\Support\Invoices;

use RuntimeException;

/** Private to one process/scenario; never points at application storage. */
final class ExportTestWorkspace
{
    public readonly string $root;

    private array $environment = [];

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/nex-sales-register-'.getmypid().'-'.bin2hex(random_bytes(12));
        foreach (['storage/app/private/sales-register-exports', 'storage/framework/views', 'storage/framework/cache/data',
            'storage/framework/sessions', 'storage/logs', 'cache', 'scratch', 'previews'] as $directory) {
            if (! mkdir($this->root.'/'.$directory, 0700, true) && ! is_dir($this->root.'/'.$directory)) {
                throw new RuntimeException('Cannot create isolated export test workspace.');
            }
        }
    }

    public function activate(): void
    {
        foreach (['LARAVEL_STORAGE_PATH' => $this->root.'/storage', 'VIEW_COMPILED_PATH' => $this->root.'/storage/framework/views',
            'APP_CONFIG_CACHE' => $this->root.'/cache/config.php', 'APP_SERVICES_CACHE' => $this->root.'/cache/services.php',
            'APP_PACKAGES_CACHE' => $this->root.'/cache/packages.php', 'APP_EVENTS_CACHE' => $this->root.'/cache/events.php',
            'APP_ROUTES_CACHE' => $this->root.'/cache/routes.php', 'SALES_REGISTER_PREVIEW_DIR' => $this->root.'/previews'] as $key => $value) {
            // Laravel recognizes slash-prefixed absolute cache paths, not Windows drive letters.
            if (PHP_OS_FAMILY === 'Windows' && str_starts_with($key, 'APP_')) {
                if (strcasecmp(substr($value, 0, 2), substr(getcwd(), 0, 2)) !== 0) {
                    throw new RuntimeException('Test temp directory must be on the project drive.');
                }
                $value = substr(str_replace('/', '\\', $value), 2);
            }
            $this->environment[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    public function restore(): void
    {
        foreach ($this->environment as $key => [$process, $env, $server]) {
            putenv($process === false ? $key : $key.'='.$process);
            unset($_ENV[$key], $_SERVER[$key]);
            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
        }
        $this->environment = [];
    }

    public function exportFiles(): array
    {
        return glob($this->root.'/storage/app/private/sales-register-exports/*') ?: [];
    }

    public function remove(): void
    {
        $root = realpath($this->root);
        if ($root === false || is_link($this->root)) {
            throw new RuntimeException('Invalid test workspace root.');
        }
        $remove = function (string $path) use (&$remove, $root): void {
            if (is_link($path)) {
                // Unlink only the link; never traverse a target outside the workspace.
                if (! unlink($path)) {
                    throw new RuntimeException('Cannot remove test link.');
                }

                return;
            }
            $resolved = realpath($path);
            if ($resolved === false || ($resolved !== $root && ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Refusing cleanup outside test workspace.');
            }
            if (is_dir($path)) {
                foreach (scandir($path) as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        $remove($path.DIRECTORY_SEPARATOR.$entry);
                    }
                }
                if (! rmdir($path)) {
                    throw new RuntimeException('Cannot remove test directory.');
                }
            } elseif (! unlink($path)) {
                throw new RuntimeException('Cannot remove test file.');
            }
        };
        $remove($this->root);
    }
}
