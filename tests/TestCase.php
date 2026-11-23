<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use PDO;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected array $verifiedTestDatabase;

    protected function setUpTraits(): array
    {
        $traits = class_uses_recursive(static::class);

        foreach ([RefreshDatabase::class, LazilyRefreshDatabase::class, DatabaseMigrations::class, DatabaseTruncation::class] as $trait) {
            if (isset($traits[$trait])) {
                throw new RuntimeException("Reset-based test trait is prohibited [{$trait}].");
            }
        }

        if (isset($traits[DatabaseTransactions::class]) && array_diff($this->connectionsToTransact(), [null, 'pgsql'])) {
            throw new RuntimeException('Test transactions may only use the verified PostgreSQL connection.');
        }

        $this->verifiedTestDatabase = $this->verifyTestDatabase();

        fwrite(STDERR, sprintf(
            "\n[Test DB verified] %s | %s:%s | database=%s | role=%s | %s\n",
            $this->verifiedTestDatabase['environment'],
            getenv('FILMUNITY_TEST_DB_HOST'),
            getenv('FILMUNITY_TEST_DB_PORT'),
            $this->verifiedTestDatabase['database'],
            $this->verifiedTestDatabase['role'],
            static::class,
        ));

        return parent::setUpTraits();
    }

    private function verifyTestDatabase(): array
    {
        if (! $this->app->environment('testing') || config('app.env') !== 'testing' || config('database.default') !== 'pgsql') {
            throw new RuntimeException('Tests require APP_ENV=testing and the pgsql default connection.');
        }

        $expected = [
            'driver' => 'pgsql',
            'database' => 'filmunity_test',
            'host' => getenv('FILMUNITY_TEST_DB_HOST'),
            'port' => getenv('FILMUNITY_TEST_DB_PORT'),
            'username' => getenv('FILMUNITY_TEST_DB_USER'),
        ];

        $database = $this->app->make('db');
        // Resolve Laravel's URL and connection settings before opening PDO.
        $connection = $database->connection('pgsql');
        $config = $connection->getConfig();
        $configured = config('database.connections.pgsql');

        if (isset($configured['read']) || isset($configured['write'])) {
            throw new RuntimeException('Tests require a single PostgreSQL endpoint.');
        }

        foreach ($expected as $key => $value) {
            $actual = $config[$key] ?? null;

            if ($value === false || $value === '' || ! is_scalar($actual) || (string) $actual !== $value) {
                throw new RuntimeException("PostgreSQL test configuration does not match the approved target [{$key}].");
            }
        }

        $target = $connection->getPdo()->query(
            'select current_database() as database_name, current_user as role_name, session_user as session_role'
        )->fetch(PDO::FETCH_ASSOC);

        if (! is_array($target) || $target['database_name'] !== $expected['database']
            || $target['role_name'] !== $expected['username'] || $target['session_role'] !== $expected['username']) {
            throw new RuntimeException('The PostgreSQL session does not match the approved test database and role.');
        }

        return [
            'environment' => $this->app->environment(),
            'connection' => $connection->getName(),
            'database' => $target['database_name'],
            'role' => $target['role_name'],
        ];
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
