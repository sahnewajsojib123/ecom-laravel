<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\OrderManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($d + ['is_admin' => true])) {
            return response()->json(['message' => 'Invalid administrator credentials.'], 422);
        }$r->session()->regenerate();

        return response()->json(['name' => $r->user()->name]);
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function data()
    {
        return ['user' => Auth::user()->only('name', 'email'), 'products' => Product::with('category')->latest()->get(), 'variants' => ProductVariant::with('product')->orderBy('product_id')->orderBy('id')->get(), 'outlets' => Outlet::all(), 'categories' => Category::all(), 'inventories' => Inventory::with('product', 'outlet', 'variant')->get(), 'banners' => Banner::all(), 'settings' => Setting::pluck('value', 'key'), 'customers' => Customer::withCount('orders')->withSum('orders', 'total')->latest()->get(), 'orders' => Order::with('items', 'outlet')->latest()->get()];
    }

    public function save(Request $r, string $type, ?int $id = null)
    {
        $models = ['products' => Product::class, 'variants' => ProductVariant::class, 'outlets' => Outlet::class, 'categories' => Category::class, 'inventories' => Inventory::class, 'banners' => Banner::class];
        abort_unless(isset($models[$type]), 404);
        $rules = match ($type) {
            'products' => ['name' => 'required|string|max:200', 'sku' => ['required', 'string', 'max:80', Rule::unique('products')->ignore($id)], 'brand' => 'required|string|max:80', 'category_id' => 'required|exists:categories,id', 'description' => 'required|string|max:10000', 'image' => 'required|url:http,https|max:2000', 'featured' => 'required|boolean', 'active' => 'required|boolean'],
            'outlets' => ['country_code' => 'sometimes|required|string|size:2', 'district' => 'nullable|string|max:100', 'name' => 'required|string|max:100', 'address' => 'required|string|max:255', 'phone' => 'required|string|max:40', 'active' => 'required|boolean'],
            'categories' => ['name' => ['required', 'string', 'max:100', Rule::unique('categories')->ignore($id)], 'icon' => 'required|in:smartphone,laptop,tablet,headphones,watch,plug,monitor,gamepad'],
            'variants' => ['product_id' => 'required|integer|exists:products,id', 'name' => 'required|string|max:120', 'sku' => ['required', 'string', 'max:80', Rule::unique('product_variants')->ignore($id)], 'options' => 'nullable|array:color,storage,size', 'options.*' => 'nullable|string|max:80', 'active' => 'required|boolean'],
            'inventories' => ['product_id' => 'required|integer|exists:products,id', 'variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $r->input('product_id'))], 'outlet_id' => 'required|exists:outlets,id', 'price' => 'required|integer|min:1|max:100000000', 'compare_price' => 'nullable|integer|gte:price|max:100000000', 'quantity' => 'required|integer|min:0|max:1000000'],
            'banners' => ['eyebrow' => 'required|string|max:100', 'title' => 'required|string|max:150', 'subtitle' => 'required|string|max:250', 'button_text' => 'required|string|max:60', 'product_id' => 'nullable|exists:products,id', 'background' => ['required', 'regex:/^#[a-fA-F0-9]{6}$/'], 'active' => 'required|boolean']
        };
        if ($type === 'products' && ! $id) {
            $rules['sku'][] = Rule::unique('product_variants');
        }
        $d = $r->validate($rules);
        if ($type === 'inventories' && empty($d['variant_id'])) {
            $choices = ProductVariant::where('product_id', $d['product_id'])->where('active', true)->get();
            if ($choices->count() !== 1) {
                throw ValidationException::withMessages(['variant_id' => 'Choose a variant.']);
            }$d['variant_id'] = $choices->first()->id;
        }
        $model = $models[$type];

        return DB::transaction(function () use ($model, $id, $d, $type) {
            if ($type === 'inventories') {
                Outlet::whereKey($d['outlet_id'])->lockForUpdate()->firstOrFail();
            }
            $item = $id ? $model::whereKey($id)->lockForUpdate()->firstOrFail() : new $model;
            if ($type === 'variants' && $id) {
                abort_unless($item->product_id == (int) $d['product_id'], 422, 'A variant cannot be moved to another product.');
            }
            if ($type === 'inventories' && Inventory::where('variant_id', $d['variant_id'])->where('outlet_id', $d['outlet_id'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['variant_id' => 'This variant already has inventory at the selected outlet.']);
            }
            if ($type === 'inventories' && $id) {
                abort_unless($item->product_id == (int) $d['product_id'] && $item->outlet_id == (int) $d['outlet_id'] && $item->variant_id == (int) $d['variant_id'], 422, 'Existing inventory belongs to its product and outlet. Create a new record for a different assignment.');
            }
            $item->fill($d)->save();

            return $item;
        }, 3);
    }

    public function settings(Request $r)
    {
        $d = $r->validate(['store_name' => 'required|string|max:80', 'tagline' => 'required|string|max:150', 'phone' => 'required|string|max:40', 'announcement' => 'required|string|max:200', 'delivery_fee' => 'required|integer|min:0|max:10000', 'audio_title' => 'sometimes|required|string|max:120', 'audio_label' => 'sometimes|required|string|max:80', 'audio_image' => 'sometimes|required|url:http,https|max:2000', 'outlet_title' => 'sometimes|required|string|max:120', 'bottom_title' => 'sometimes|required|string|max:150', 'bottom_description' => 'sometimes|required|string|max:250', 'logo_caption' => 'sometimes|required|string|max:80']);
        foreach ($d as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) $v]);
        }

        return ['ok' => true];
    }

    public function status(Request $r, Order $order)
    {
        $d = $r->validate(['status' => 'required|in:confirmed,processing,completed,cancelled']);

        return app(OrderManager::class)->apply($order, 'status', $d, 'admin:'.$r->user()->id);
    }
}
