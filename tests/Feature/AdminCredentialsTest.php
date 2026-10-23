<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_updates_only_existing_admin_and_enables_configured_login(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com', 'is_admin' => true]);
        $customer = User::factory()->create();
        $customerHash = $customer->password;
        config(['commerce.admin_email' => $admin->email, 'commerce.admin_password' => 'new-deployment-password']);
        DB::table('sessions')->insert(['id' => 'previous-admin-session', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('sessions')->insert(['id' => 'customer-session', 'user_id' => $customer->id, 'payload' => '', 'last_activity' => time()]);
        $this->artisan('commerce:sync-admin')->assertSuccessful();
        $this->assertTrue(Hash::check('new-deployment-password', $admin->fresh()->password));
        $this->assertSame($customerHash, $customer->fresh()->password);
        $this->assertDatabaseMissing('sessions', ['id' => 'previous-admin-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'customer-session']);
        $this->postJson('/api/v1/admin/login', ['email' => $admin->email, 'password' => 'new-deployment-password'])->assertOk();
        $this->getJson('/api/v1/admin/data')->assertOk();
    }

    public function test_matching_credentials_are_not_rehashed_or_sessions_revoked(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com', 'is_admin' => true, 'password' => 'matching-deployment-password']);
        $hash = $admin->password;
        config(['commerce.admin_email' => $admin->email, 'commerce.admin_password' => 'matching-deployment-password']);
        DB::table('sessions')->insert(['id' => 'retained-session', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);
        $this->artisan('commerce:sync-admin')->assertSuccessful();
        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertDatabaseHas('sessions', ['id' => 'retained-session']);
    }

    public function test_invalid_settings_do_not_modify_existing_credentials(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com', 'is_admin' => true]);
        $hash = $admin->password;
        config(['commerce.admin_email' => $admin->email, 'commerce.admin_password' => 'short']);
        $this->artisan('commerce:sync-admin')->assertFailed();
        $this->assertSame($hash, $admin->fresh()->password);
    }

    public function test_customer_or_missing_email_cannot_be_promoted_to_admin(): void
    {
        $customer = User::factory()->create(['email' => 'customer@example.com']);
        $hash = $customer->password;
        config(['commerce.admin_email' => $customer->email, 'commerce.admin_password' => 'new-deployment-password']);
        $this->artisan('commerce:sync-admin')->assertFailed();
        $this->assertSame($hash, $customer->fresh()->password);
        $this->assertFalse((bool) $customer->fresh()->is_admin);
        config(['commerce.admin_email' => 'missing@example.com']);
        $this->artisan('commerce:sync-admin')->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }
}
