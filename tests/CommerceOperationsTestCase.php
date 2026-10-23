<?php

namespace Tests;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

abstract class CommerceOperationsTestCase extends TestCase
{
    use RefreshDatabase;

    protected function shop(): array
    {
        $outlet = Outlet::create(['name' => 'Test outlet', 'address' => 'Dhaka', 'phone' => '01700000000']);
        $category = Category::create(['name' => 'Phones']);
        $product = Product::create(['name' => 'Phone', 'sku' => 'PHONE', 'category_id' => $category->id, 'brand' => 'Test', 'description' => 'Test phone', 'image' => 'https://example.com/a.png']);
        $variant = $product->variants()->firstOrFail();
        $inventory = Inventory::create(['product_id' => $product->id, 'variant_id' => $variant->id, 'outlet_id' => $outlet->id, 'price' => 1000, 'quantity' => 10]);

        return [$outlet, $product, $variant, $inventory];
    }

    protected function payload(array $shop): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'outlet_id' => $shop[0]->id, 'customer_name' => 'Test Buyer', 'phone' => '01700000000', 'email' => 'buyer@example.com', 'address' => 'Original address', 'fulfillment' => 'delivery', 'items' => [['product_id' => $shop[1]->id, 'variant_id' => $shop[2]->id, 'quantity' => 2]]];
    }

    protected function placed(array $shop): Order
    {
        $this->postJson('/api/v1/orders', $this->payload($shop))->assertCreated();

        return Order::latest('id')->firstOrFail();
    }

    protected function admin(): User
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);

        return $user;
    }

    protected function operation(Order $order, string $action, array $data = []): TestResponse
    {
        return $this->postJson('/api/v1/admin/orders/'.$order->id.'/actions/'.$action, $data + ['revision' => $order->fresh()->revision, 'operation_key' => (string) Str::uuid()]);
    }
}
