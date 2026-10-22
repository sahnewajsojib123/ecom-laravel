<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductVariantsTest extends TestCase
{
    use RefreshDatabase;

    private function variants(): array
    {
        $outlet = Outlet::create(['name' => 'Test outlet', 'address' => 'Dhaka', 'phone' => '01700000000']);
        $category = Category::create(['name' => 'Phones']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Phone', 'sku' => 'PARENT-1', 'brand' => 'Test', 'description' => 'Test phone', 'image' => 'https://example.com/phone.png']);
        $black = $product->variants()->firstOrFail();
        $black->update(['name' => 'Black / 128GB', 'options' => ['color' => 'Black', 'storage' => '128GB']]);
        $blue = $product->variants()->create(['name' => 'Blue / 256GB', 'sku' => 'BLUE-256', 'options' => ['color' => 'Blue', 'storage' => '256GB']]);
        $stock = Inventory::create(['product_id' => $product->id, 'variant_id' => $black->id, 'outlet_id' => $outlet->id, 'price' => 1000, 'quantity' => 3]);
        $blueStock = Inventory::create(['product_id' => $product->id, 'variant_id' => $blue->id, 'outlet_id' => $outlet->id, 'price' => 1500, 'quantity' => 5]);

        return [$outlet, $product, $black, $blue, $stock, $blueStock];
    }

    private function payload(Outlet $outlet, Product $product, ProductVariant $variant): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'outlet_id' => $outlet->id, 'customer_name' => 'Variant customer', 'phone' => '01700000000', 'address' => 'Pickup', 'fulfillment' => 'pickup', 'items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2]]];
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    public function test_catalog_has_one_card_per_product_and_variant_specific_details(): void
    {
        [$outlet,$product,$black,$blue] = $this->variants();

        $this->getJson('/api/v1/products?outlet_id='.$outlet->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.price', 1000)->assertJsonPath('data.0.quantity', 8)->assertJsonPath('data.0.has_variants', true)->assertJsonPath('data.0.variant_id', null);
        $this->getJson('/api/v1/products/'.$product->id)->assertOk()->assertJsonCount(2, 'variants')->assertJsonPath('variants.1.options.storage', '256GB')->assertJsonPath('inventories.1.variant_id', $blue->id)->assertJsonPath('inventories.1.price', 1500);
    }

    public function test_orders_reserve_only_the_selected_variant_and_snapshot_its_sku(): void
    {
        [$outlet,$product,$black,$blue,$stock,$blueStock] = $this->variants();

        $this->postJson('/api/v1/orders', $this->payload($outlet, $product, $blue))->assertCreated()->assertJsonPath('total', 3000);

        $this->assertDatabaseHas('inventories', ['id' => $stock->id, 'quantity' => 3]);
        $this->assertDatabaseHas('inventories', ['id' => $blueStock->id, 'quantity' => 3]);
        $this->assertDatabaseHas('order_items', ['variant_id' => $blue->id, 'variant_name' => 'Blue / 256GB', 'sku' => 'BLUE-256', 'unit_price' => 1500]);
    }

    public function test_two_variants_of_one_product_can_be_ordered_together(): void
    {
        [$outlet,$product,$black,$blue] = $this->variants();
        $payload = $this->payload($outlet, $product, $black);
        $payload['items'][] = ['product_id' => $product->id, 'variant_id' => $blue->id, 'quantity' => 1];

        $this->postJson('/api/v1/orders', $payload)->assertCreated()->assertJsonPath('total', 3500);

        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_variant_selection_is_required_and_must_belong_to_the_product(): void
    {
        [$outlet,$product,$black] = $this->variants();
        $payload = $this->payload($outlet, $product, $black);
        unset($payload['items'][0]['variant_id']);
        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable();
        $other = Product::create(['category_id' => $product->category_id, 'name' => 'Other', 'sku' => 'OTHER', 'brand' => 'Test', 'description' => 'Other', 'image' => 'https://example.com/other.png']);
        $payload['items'][0]['variant_id'] = $other->variants()->first()->id;

        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_sold_out_or_disabled_variants_cannot_use_other_variants_stock(): void
    {
        [$outlet,$product,$black,$blue,$stock,$blueStock] = $this->variants();
        $blueStock->update(['quantity' => 0]);
        $this->postJson('/api/v1/orders', $this->payload($outlet, $product, $blue))->assertUnprocessable();
        $blueStock->update(['quantity' => 5]);
        $blue->update(['active' => false]);

        $this->postJson('/api/v1/orders', $this->payload($outlet, $product, $blue))->assertUnprocessable();
        $this->getJson('/api/v1/products/'.$product->id)->assertJsonCount(1, 'variants')->assertJsonCount(1, 'inventories');

        $this->assertDatabaseHas('inventories', ['id' => $stock->id, 'quantity' => 3]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_duplicate_variant_lines_are_rejected_and_reservations_rolled_back(): void
    {
        [$outlet,$product,$black,$blue,$stock] = $this->variants();
        $payload = $this->payload($outlet, $product, $black);
        $payload['items'][] = $payload['items'][0];

        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable();

        $this->assertDatabaseHas('inventories', ['id' => $stock->id, 'quantity' => 3]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_variant_stock_and_price_are_outlet_specific(): void
    {
        [$outlet,$product,$black,$blue,$stock,$blueStock] = $this->variants();
        $other = Outlet::create(['name' => 'Other outlet', 'address' => 'Other', 'phone' => '01700000000']);
        Inventory::create(['product_id' => $product->id, 'variant_id' => $blue->id, 'outlet_id' => $other->id, 'price' => 2000, 'quantity' => 4]);

        $this->postJson('/api/v1/orders', $this->payload($other, $product, $blue))->assertCreated()->assertJsonPath('total', 4000);

        $this->assertDatabaseHas('inventories', ['id' => $blueStock->id, 'quantity' => 5]);
    }

    public function test_cancellation_restores_only_the_ordered_variant_once(): void
    {
        [$outlet,$product,$black,$blue,$stock,$blueStock] = $this->variants();
        $this->postJson('/api/v1/orders', $this->payload($outlet, $product, $blue))->assertCreated();
        $order = Order::firstOrFail();
        $this->actingAs($this->admin())->patchJson('/api/v1/admin/orders/'.$order->id, ['status' => 'cancelled'])->assertOk();
        $this->patchJson('/api/v1/admin/orders/'.$order->id, ['status' => 'cancelled'])->assertUnprocessable();

        $this->assertDatabaseHas('inventories', ['id' => $stock->id, 'quantity' => 3]);
        $this->assertDatabaseHas('inventories', ['id' => $blueStock->id, 'quantity' => 5]);
    }

    public function test_admin_can_manage_variant_stock_but_cannot_duplicate_or_reassign_it(): void
    {
        [$outlet,$product,$black,$blue,$stock] = $this->variants();
        $this->actingAs($this->admin())->postJson('/api/v1/admin/variants', ['product_id' => $product->id, 'name' => 'White / 512GB', 'sku' => 'WHITE-512', 'options' => ['color' => 'White', 'storage' => '512GB'], 'active' => true])->assertCreated();
        $variant = ProductVariant::where('sku', 'WHITE-512')->firstOrFail();
        $payload = ['product_id' => $product->id, 'variant_id' => $variant->id, 'outlet_id' => $outlet->id, 'price' => 3000, 'quantity' => 2];
        $this->postJson('/api/v1/admin/inventories', $payload)->assertCreated();
        $this->postJson('/api/v1/admin/inventories', $payload)->assertUnprocessable();
        $this->putJson('/api/v1/admin/inventories/'.$stock->id, $payload)->assertUnprocessable();

        $this->assertDatabaseHas('inventories', ['variant_id' => $variant->id, 'price' => 3000, 'quantity' => 2]);
        $this->assertDatabaseHas('inventories', ['id' => $stock->id, 'variant_id' => $black->id, 'quantity' => 3]);
    }

    public function test_migration_preserves_existing_stock_and_links_historical_orders(): void
    {
        $migration = require database_path('migrations/2026_10_22_203338_add_product_variants.php');
        $migration->down();
        $category = DB::table('categories')->insertGetId(['name' => 'Legacy']);
        $product = DB::table('products')->insertGetId(['category_id' => $category, 'name' => 'Legacy phone', 'sku' => 'LEGACY-1', 'brand' => 'Legacy', 'description' => 'Legacy', 'image' => 'https://example.com/legacy.png']);
        $outlet = DB::table('outlets')->insertGetId(['name' => 'Legacy outlet', 'address' => 'Dhaka', 'phone' => '01700000000']);
        DB::table('inventories')->insert(['product_id' => $product, 'outlet_id' => $outlet, 'price' => 800, 'quantity' => 7]);
        $order = DB::table('orders')->insertGetId(['reference' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'outlet_id' => $outlet, 'customer_name' => 'Legacy customer', 'phone' => '01700000000', 'address' => 'Pickup', 'fulfillment' => 'pickup', 'subtotal' => 800, 'delivery_fee' => 0, 'total' => 800]);
        DB::table('order_items')->insert(['order_id' => $order, 'product_id' => $product, 'name' => 'Original name', 'quantity' => 1, 'unit_price' => 800]);

        $migration->up();

        $variant = ProductVariant::where('product_id', $product)->firstOrFail();
        $this->assertDatabaseHas('inventories', ['product_id' => $product, 'variant_id' => $variant->id, 'price' => 800, 'quantity' => 7]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order, 'variant_id' => $variant->id, 'sku' => 'LEGACY-1', 'name' => 'Original name', 'unit_price' => 800]);
        $this->assertDatabaseHas('orders', ['id' => $order, 'total' => 800]);
    }
}
