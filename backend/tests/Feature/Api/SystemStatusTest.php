<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class SystemStatusTest extends TestCase
{
    public function test_the_public_api_status_endpoint_is_available(): void
    {
        $this->getJson('/api/v1/status')
            ->assertOk()
            ->assertJsonPath('data.service', 'praxis-penal-api')
            ->assertJsonPath('data.status', 'available');
    }
}
