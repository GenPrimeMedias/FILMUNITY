<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_public_api_health_endpoint_returns_expected_json(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'filmunity-api',
            ])
            ->assertHeader('Content-Type', 'application/json');
    }
}
