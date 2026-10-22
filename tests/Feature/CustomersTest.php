<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomersTest extends TestCase
{
    use RefreshDatabase;

    private function checkout(): array
    {
        $outlet = Outlet::create(['name' => 'Test', 'address' => 'Dhaka', 'phone' => '01700000000']);
        $category = Category::create(['name' => 'Phones']);
        $product = Product::create(['name' => 'Phone', 'sku' => 'PHONE', 'category_id' => $category->id, 'brand' => 'Test', 'description' => 'Phone', 'image' => 'https://example.com/p.png']);
        Inventory::create(['product_id' => $product->id, 'outlet_id' => $outlet->id, 'price' => 100, 'quantity' => 10]);

        return ['idempotency_key' => (string) Str::uuid(), 'outlet_id' => $outlet->id, 'customer_name' => 'Test Customer', 'phone' => '01700-000000', 'email' => 'customer@example.com', 'address' => 'Original address', 'fulfillment' => 'pickup', 'items' => [['product_id' => $product->id, 'quantity' => 1]]];
    }

    public function test_checkout_links_repeat_customers_without_changing_order_snapshots(): void
    {
        $payload = $this->checkout();
        $this->postJson('/api/v1/orders', $payload)->assertCreated();
        $this->postJson('/api/v1/orders', $payload)->assertOk();
        $payload['idempotency_key'] = (string) Str::uuid();
        $payload['phone'] = '+8801700000000';
        $payload['address'] = 'New address';
        $payload['customer_id'] = 999;
        $this->postJson('/api/v1/orders', $payload)->assertCreated();
        $this->assertDatabaseCount('customers', 1);
        $customer = Customer::firstOrFail();
        $this->assertSame('8801700000000', $customer->phone);
        $this->assertSame(2, $customer->orders()->count());
        $this->assertTrue(Order::firstOrFail()->customer->is($customer));
        $this->assertSame('Original address', Order::firstOrFail()->address);
        $this->assertSame('New address', Order::latest('id')->first()->address);
        $this->assertSame(Customer::class, config('chattrn.models.customer'));
    }

    public function test_failed_checkout_does_not_create_customer(): void
    {
        $payload = $this->checkout();
        $payload['items'][0]['quantity'] = 20;
        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_customer_records_are_only_available_to_admin(): void
    {
        $customer = Customer::factory()->create();
        $this->getJson('/api/v1/admin/data')->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/data')->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->getJson('/api/v1/admin/data')->assertOk()->assertJsonPath('customers.0.id', $customer->id);
    }

    public function test_migration_backfills_existing_orders_and_preserves_details(): void
    {
        $migration = require database_path('migrations/2026_10_22_224844_create_customers_and_link_orders.php');
        $migration->down();
        $outlet = Outlet::create(['name' => 'Test', 'address' => 'Dhaka', 'phone' => '01700000000']);
        foreach (['01700000000', '+8801700000000'] as $phone) {
            DB::table('orders')->insert(['outlet_id' => $outlet->id, 'reference' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'customer_name' => 'Legacy Customer', 'phone' => $phone, 'address' => 'Legacy address', 'fulfillment' => 'pickup', 'subtotal' => 100, 'delivery_fee' => 0, 'total' => 100, 'created_at' => now(), 'updated_at' => now()]);
        }
        $migration->up();
        $this->assertDatabaseCount('customers', 1);
        $this->assertSame(2, Customer::firstOrFail()->orders()->count());
        $this->assertDatabaseHas('orders', ['phone' => '01700000000', 'address' => 'Legacy address', 'total' => 100]);
    }
}
