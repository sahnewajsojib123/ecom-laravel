<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreController extends Controller
{
    public function bootstrap()
    {
        return response()->json(['outlets' => Outlet::where('active', true)->get(), 'categories' => Category::all(), 'banners' => Banner::where('active', true)->with('product')->get(), 'settings' => Setting::pluck('value', 'key')]);
    }

    public function products(Request $r)
    {
        $d = $r->validate(['outlet_id' => 'required|integer|exists:outlets,id', 'q' => 'nullable|string|max:100', 'category_id' => 'nullable|integer', 'sort' => 'nullable|in:featured,price_asc,price_desc', 'featured' => 'nullable|boolean']);
        abort_unless(Outlet::where('id', $d['outlet_id'])->where('active', true)->exists(), 404);
        $stockFilter = fn ($q) => $q->where('outlet_id', $d['outlet_id'])->whereHas('variant', fn ($v) => $v->where('active', true));
        $q = Product::where('active', true)->whereHas('inventories', $stockFilter)->with(['category', 'variants' => fn ($v) => $v->where('active', true), 'inventories' => $stockFilter])->withMin(['inventories as starting_price' => $stockFilter], 'price');
        if (! empty($d['q'])) {
            $q->where(fn ($s) => $s->where('name', 'like', '%'.$d['q'].'%')->orWhere('brand', 'like', '%'.$d['q'].'%'));
        }
        if (! empty($d['category_id'])) {
            $q->where('category_id', $d['category_id']);
        }
        if (! empty($d['featured'])) {
            $q->where('featured', true);
        }
        match ($d['sort'] ?? 'featured') {
            'price_asc' => $q->orderBy('starting_price'),'price_desc' => $q->orderByDesc('starting_price'),default => $q->orderByDesc('featured')->orderBy('id')
        };

        return $q->paginate(24)->through(function (Product $p) use ($d) {
            $cheapest = $p->inventories->sortBy('price')->first();

            return ['id' => $cheapest->id, 'product_id' => $p->id, 'outlet_id' => (int) $d['outlet_id'], 'variant_id' => $p->variants->count() === 1 ? $p->variants->first()->id : null, 'has_variants' => $p->variants->count() > 1, 'price' => $cheapest->price, 'compare_price' => $cheapest->compare_price, 'quantity' => $p->inventories->sum('quantity'), 'product' => $p->makeHidden(['inventories', 'starting_price'])];
        });
    }

    public function product(Product $product)
    {
        abort_unless($product->active, 404);

        return $product->load(['category', 'variants' => fn ($v) => $v->where('active', true)->orderBy('id'), 'inventories' => fn ($q) => $q->whereHas('variant', fn ($v) => $v->where('active', true))->whereHas('outlet', fn ($o) => $o->where('active', true))->with('outlet', 'variant')]);
    }

    public function order(Request $r)
    {
        $d = $r->validate(['idempotency_key' => 'required|uuid', 'outlet_id' => 'required|integer|exists:outlets,id', 'customer_name' => 'required|string|max:100', 'phone' => ['required', 'regex:/^\+?[0-9 ()-]{8,20}$/'], 'email' => 'nullable|email|max:200', 'address' => 'required|string|max:1000', 'fulfillment' => 'required|in:delivery,pickup', 'items' => 'required|array|min:1|max:50', 'items.*.product_id' => 'required|integer|exists:products,id', 'items.*.variant_id' => 'nullable|integer|exists:product_variants,id', 'items.*.quantity' => 'required|integer|min:1|max:20']);
        abort_unless(Outlet::where('id', $d['outlet_id'])->where('active', true)->exists(), 422, 'Outlet unavailable.');

        return DB::transaction(function () use ($d) {
            Outlet::whereKey($d['outlet_id'])->lockForUpdate()->firstOrFail();
            if ($existing = Order::where('idempotency_key', $d['idempotency_key'])->first()) {
                return response()->json(['reference' => $existing->reference, 'total' => $existing->total], 200);
            }
            $items = [];
            $subtotal = 0;
            $seen = [];
            foreach (collect($d['items'])->sortBy(fn ($i) => sprintf('%020d:%020d', $i['product_id'], $i['variant_id'] ?? 0)) as $line) {
                $variants = ProductVariant::where('product_id', $line['product_id'])->where('active', true);
                if (! empty($line['variant_id'])) {
                    $variant = $variants->whereKey($line['variant_id'])->first();
                } else {
                    $choices = $variants->get();
                    $variant = $choices->count() === 1 ? $choices->first() : null;
                }
                if (! $variant) {
                    throw ValidationException::withMessages(['items' => 'Choose an available variant for each product.']);
                }
                if (isset($seen[$variant->id])) {
                    throw ValidationException::withMessages(['items' => 'Each product variant may appear only once.']);
                }$seen[$variant->id] = true;
                $stock = Inventory::with('product')->where('outlet_id', $d['outlet_id'])->where('product_id', $line['product_id'])->where('variant_id', $variant->id)->lockForUpdate()->first();
                if (! $stock || ! $stock->product->active || $stock->quantity < $line['quantity']) {
                    throw ValidationException::withMessages(['items' => 'A selected variant is unavailable or has insufficient stock. Refresh your cart.']);
                }
                $items[] = ['product_id' => $stock->product_id, 'variant_id' => $variant->id, 'variant_name' => $variant->name, 'sku' => $variant->sku, 'name' => $stock->product->name, 'quantity' => $line['quantity'], 'unit_price' => $stock->price];
                $subtotal += $stock->price * $line['quantity'];
                $stock->decrement('quantity', $line['quantity']);
            }
            $fee = $d['fulfillment'] === 'pickup' ? 0 : (int) (Setting::where('key', 'delivery_fee')->value('value') ?? 120);
            $order = Order::create(collect($d)->except('items')->all() + ['reference' => (string) Str::uuid(), 'subtotal' => $subtotal, 'delivery_fee' => $fee, 'total' => $subtotal + $fee]);
            $order->items()->createMany($items);

            return response()->json(['reference' => $order->reference, 'total' => $order->total], 201);
        }, 3);
    }
}
