<?php

// Minimal, in-memory Laravel stand-ins. Never connects to a database or an API.

namespace Illuminate\Support\Facades {
    class DB
    {
        public static function connection($name = null): \DeployTestDb
        {
            return new \DeployTestDb;
        }
    }
}

namespace Modules\Ksef\Enums {
    enum KsefEnvironment: string
    {
        case Demo = 'demo';
        case Production = 'production';
    }
}

namespace Modules\Ksef\Models {
    class KsefSetting
    {
        public const SINGLETON_KEY = 'default';

        public static function query(): \DeployTestQuery
        {
            return new \DeployTestQuery('settings');
        }
    }
}

namespace {
    use Modules\Ksef\Enums\KsefEnvironment;

    function deployTestProfile(): array
    {
        return json_decode(getenv('DEPLOY_PROFILE') ?: '{}', true, flags: JSON_THROW_ON_ERROR);
    }

    function app(): DeployTestApp
    {
        return new DeployTestApp;
    }

    function config(string $key): mixed
    {
        $profile = deployTestProfile();
        $queue = ['driver' => $profile['queue_driver'] ?? 'database', 'connection' => null, 'table' => 'jobs'];

        return match ($key) {
            'queue.connections.database' => $queue + ['retry_after' => $profile['retry_after'] ?? 300],
            'queue.connections.ksef_submit' => $queue + ['retry_after' => 240],
            'app.key' => 'FAKE-NOT-A-SECRET',
            default => [],
        };
    }

    class DeployTestApp
    {
        public function make(string $name): object
        {
            if ($name === 'migrator') {
                return new class
                {
                    public function getMigrationFiles(array $paths): array
                    {
                        return ['old' => 'old.php'];
                    }
                };
            }

            return new class
            {
                public function bootstrap(): void {}
            };
        }

        public function environment(string $environment): bool
        {
            return (deployTestProfile()['environment'] ?? 'staging') === $environment;
        }

        public function databasePath(string $path): string
        {
            return __DIR__.'/'.$path;
        }

        public function isDownForMaintenance(): bool
        {
            return false;
        }

        public function routesAreCached(): bool
        {
            return false;
        }
    }

    class DeployTestDb
    {
        public function getDriverName(): string
        {
            return deployTestProfile()['driver'] ?? 'mysql';
        }

        public function getDatabaseName(): string
        {
            return deployTestProfile()['database'] ?? 'nex_oms';
        }

        public function selectOne(string $sql): object
        {
            return (object) ['id' => 'fake-server-uuid', 'version' => deployTestProfile()['mysql_version'] ?? '8.0.46'];
        }

        public function table(string $name): DeployTestQuery
        {
            return new DeployTestQuery($name);
        }
    }

    class DeployTestQuery
    {
        public function __construct(private string $name) {}

        public function where(...$arguments): static
        {
            return $this;
        }

        public function whereIn(...$arguments): static
        {
            return $this;
        }

        public function orderBy(...$arguments): static
        {
            return $this;
        }

        public function count(): int
        {
            return (int) (deployTestProfile()['non_innodb_tables'] ?? 0);
        }

        public function exists(): bool
        {
            return (bool) (deployTestProfile()[$this->name === 'jobs' ? 'jobs' : 'active_automations'] ?? false);
        }

        public function pluck(string $column): object
        {
            return new class
            {
                public function all(): array
                {
                    return ['old'];
                }
            };
        }

        public function firstOrFail(): object
        {
            return new class
            {
                public KsefEnvironment $environment;

                public bool $automatic_submission;

                public function __construct()
                {
                    $this->environment = KsefEnvironment::from(deployTestProfile()['ksef_environment'] ?? 'demo');
                    $this->automatic_submission = (bool) (deployTestProfile()['automatic_submission'] ?? false);
                }

                public function getRawOriginal(): array
                {
                    return ['environment' => $this->environment->value, 'automatic_submission' => $this->automatic_submission];
                }
            };
        }
    }
}
