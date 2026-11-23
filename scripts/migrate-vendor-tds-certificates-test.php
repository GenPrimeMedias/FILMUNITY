<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (! $app->environment('testing') || config('app.env') !== 'testing' || config('database.default') !== 'pgsql') {
    throw new RuntimeException('Migration requires the testing environment and pgsql.');
}

$config = config('database.connections.pgsql');
if (isset($config['read']) || isset($config['write'])) {
    throw new RuntimeException('Migration requires a single PostgreSQL endpoint.');
}

$connection = $app->make('db')->connection('pgsql');
$expected = [
    'driver' => 'pgsql',
    'database' => 'filmunity_test',
    'host' => getenv('FILMUNITY_TEST_DB_HOST'),
    'port' => getenv('FILMUNITY_TEST_DB_PORT'),
    'username' => getenv('FILMUNITY_TEST_DB_USER'),
];

foreach ($expected as $key => $value) {
    $actual = $connection->getConfig($key);
    if ($value === false || $value === '' || ! is_scalar($actual) || (string) $actual !== $value) {
        throw new RuntimeException("Configured migration target does not match the approved target [{$key}].");
    }
}

$session = $connection->getPdo()->query(
    'select current_database() as database_name, current_user as role_name, session_user as session_role'
)->fetch(PDO::FETCH_ASSOC);

if (! is_array($session) || $session['database_name'] !== $expected['database']
    || $session['role_name'] !== $expected['username'] || $session['session_role'] !== $expected['username']) {
    throw new RuntimeException('PostgreSQL did not confirm the approved migration database and role.');
}

fwrite(STDOUT, "[Migration DB verified] {$expected['host']}:{$expected['port']} / {$session['database_name']} / {$session['role_name']}\n");

$status = $kernel->call('migrate', [
    '--database' => 'pgsql',
    '--path' => ['database/migrations/2026_11_21_050000_add_vendor_tds_certificates.php'],
], new ConsoleOutput);

exit($status);
