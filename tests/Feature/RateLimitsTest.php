<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RateLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_browsing_does_not_consume_administrator_login_attempts(): void
    {
        $admin = User::factory()->create(['password' => Hash::make('test-admin-password')]);
        $admin->forceFill(['is_admin' => true])->save();
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/v1/storefront')->assertOk();
        }

        $this->postJson('/api/v1/admin/login', ['email' => $admin->email, 'password' => 'test-admin-password'])
            ->assertOk()->assertJsonPath('name', $admin->name);

        $this->assertAuthenticatedAs($admin);
    }

    public function test_admin_login_still_blocks_after_five_failed_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/admin/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/v1/admin/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertTooManyRequests();

        $this->assertGuest();
    }
}
