<?php

use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

return new class extends ArrayObject
{
    public function __construct()
    {
        $this['config'] = new class
        {
            public function get(string $key): mixed
            {
                return match ($key) {
                    'queue.connections.database' => ['driver' => 'database', 'connection' => 'queue-db'],
                    'queue.connections.ksef_submit' => ['driver' => 'database', 'connection' => 'ksef-db'],
                    'database.default' => 'queue-db',
                    'cache.default' => 'test-cache',
                    'cache.stores.test-cache' => ['driver' => 'database', 'connection' => getenv('TEST_CACHE_DB') ?: 'cache-db'],
                    default => throw new RuntimeException('Unexpected key.'),
                };
            }
        };
        $this['db'] = new class
        {
            public function connection(string $name): object
            {
                return new class($name)
                {
                    public function __construct(private string $name) {}

                    public function getDriverName(): string
                    {
                        return 'mysql';
                    }

                    public function selectOne(string $sql, array $bindings, bool $useReadPdo): object
                    {
                        if ($sql !== 'SELECT 1' || $bindings !== [] || $useReadPdo) {
                            throw new RuntimeException('Unexpected database operation.');
                        }
                        echo $this->name, PHP_EOL;
                        $mysqlCode = (int) getenv('TEST_MYSQL_ERROR');
                        if ($mysqlCode && $this->name === 'cache-db') {
                            $cause = new PDOException('secret test password');
                            $cause->errorInfo = ['HY000', $mysqlCode, 'secret test password'];
                            throw new RuntimeException('wrapped secret', previous: $cause);
                        }

                        return (object) ['1' => 1];
                    }
                };
            }
        };
    }

    public function bootstrapWith(array $bootstrappers): void
    {
        if ($bootstrappers !== [
            LoadEnvironmentVariables::class,
            LoadConfiguration::class,
        ]) {
            throw new RuntimeException('Unexpected bootstrap.');
        }
    }

    public function register(string $provider): void
    {
        if ($provider !== DatabaseServiceProvider::class) {
            throw new RuntimeException('Unexpected service provider.');
        }
    }
};
