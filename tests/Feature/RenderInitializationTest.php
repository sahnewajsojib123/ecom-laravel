<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RenderInitializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_initializes_empty_store_and_preserves_changes_on_restart(): void
    {
        config(['commerce.admin_email' => 'render-admin@example.com', 'commerce.admin_password' => 'initial-demo-password']);
        $this->artisan('commerce:initialize-demo')->assertSuccessful();
        $this->assertDatabaseCount('products', 12);
        $this->assertDatabaseCount('outlets', 3);
        $this->assertDatabaseCount('inventories', 36);
        $this->assertDatabaseHas('users', ['email' => 'render-admin@example.com', 'is_admin' => true]);
        $admin = User::where('email', 'render-admin@example.com')->firstOrFail();
        $admin->password = Hash::make('changed-admin-password');
        $admin->save();
        $inventory = Inventory::firstOrFail();
        $inventory->update(['quantity' => 2, 'price' => 12345]);
        Setting::where('key', 'store_name')->update(['value' => 'My updated store']);
        config(['commerce.admin_password' => 'a-different-env-password']);

        $this->artisan('commerce:initialize-demo')->assertSuccessful();

        $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'quantity' => 2, 'price' => 12345]);
        $this->assertDatabaseHas('settings', ['key' => 'store_name', 'value' => 'My updated store']);
        $this->assertTrue(Hash::check('changed-admin-password', $admin->fresh()->password));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_rejects_missing_credentials_without_creating_demo_data(): void
    {
        config(['commerce.admin_password' => null]);

        $this->artisan('commerce:initialize-demo')->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('outlets', 0);
    }

    public function test_leaves_an_existing_store_unchanged(): void
    {
        $admin = User::factory()->create();
        config(['commerce.admin_password' => 'another-demo-password']);

        $this->artisan('commerce:initialize-demo')->assertSuccessful();

        $this->assertModelExists($admin);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('outlets', 0);
    }
}
