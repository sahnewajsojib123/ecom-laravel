<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderItemProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_items_resolve_their_product_including_sku(): void
    {
        $outlet = Outlet::create(['name' => 'Test outlet', 'address' => 'Dhaka', 'phone' => '01700000000']);
        $category = Category::create(['name' => 'Phones']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test phone',
            'sku' => 'PHONE-RELATION-001',
            'brand' => 'Test',
            'description' => 'A test product',
            'image' => 'https://example.com/phone.png',
        ]);
        Inventory::create(['product_id' => $product->id, 'outlet_id' => $outlet->id, 'price' => 1000, 'quantity' => 2]);

        $this->postJson('/api/v1/orders', [
            'idempotency_key' => (string) Str::uuid(),
            'outlet_id' => $outlet->id,
            'customer_name' => 'Test customer',
            'phone' => '01700000000',
            'address' => 'Outlet pickup',
            'fulfillment' => 'pickup',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        $order = Order::with('items.product')->firstOrFail();
        $this->assertCount(1, $order->items);
        $item = $order->items->first();
        $this->assertTrue($item->product->is($product));
        $this->assertSame('PHONE-RELATION-001', $item->product->sku);
        $this->assertSame('Test phone', $item->product->name);
    }
}
