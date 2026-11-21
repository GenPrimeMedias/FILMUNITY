<?php

namespace Tests\Unit;

use Tests\TestCase;

class TestDatabaseConfigurationTest extends TestCase
{
    public function test_database_configuration_is_isolated(): void
    {
        $this->assertSame('testing', $this->verifiedTestDatabase['environment']);
        $this->assertSame('pgsql', $this->verifiedTestDatabase['connection']);
        $this->assertSame('filmunity_test', $this->verifiedTestDatabase['database']);
        $this->assertSame(getenv('FILMUNITY_TEST_DB_USER'), $this->verifiedTestDatabase['role']);
    }
}
