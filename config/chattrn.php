<?php

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;

// Rewritten by `php artisan chattrn:map`. See vendor/chattrn/connector/config/chattrn.php
// for what each key means.
return [
    'route_prefix' => '/chattrn/api/2026-10',
    'api_version' => '2026-10',
    'access_token' => env('CHATTRN_ACCESS_TOKEN'),
    'backend_url' => env('CHATTRN_BACKEND_URL', 'https://api.chattrn.com'),
    'connection_token' => env('CHATTRN_TOKEN'),
    'store' => [
        'name' => env('APP_NAME', 'My Store'),
        'domain' => env('APP_URL'),
        'currency' => 'BDT',
        'country_code' => 'BD',
        'delivery_mode' => 'zone',
        'product_url_template' => null,
        'cart_url_template' => null,
        'primary_location_id' => '1',
    ],
    'models' => [
        'product' => Product::class,
        'variant' => ProductVariant::class,
        'order' => Order::class,
        'customer' => Customer::class,
        'location' => Outlet::class,
    ],
    'inventory' => [
        'model' => Inventory::class,
        'columns' => [
            'product_id' => 'product_id',
            'variant_id' => 'variant_id',
            'location_id' => 'outlet_id',
            'price' => 'price',
            'stock' => 'quantity',
            'compare_price' => 'compare_price',
        ],
    ],
    'columns' => [
        'product' => [
            'title' => 'name',
            'handle' => 'id',
            'vendor' => 'brand',
            'status' => null,
            'image_url' => 'image',
        ],
        'variant' => [
            'title' => 'name',
            'price' => 'price',
            'compare_at_price' => null,
            'sku' => 'sku',
            'stock' => 'stock',
            'options_json' => 'options',
        ],
        'order' => [
            'number' => 'reference',
            'phone' => 'phone',
            'total' => 'total',
            'currency' => null,
            'financial_status' => 'status',
            'fulfillment_status' => null,
            'customer_name' => 'customer_name',
            'address' => 'address',
        ],
        'customer' => [
            'phone' => 'phone',
            'email' => 'email',
        ],
        'order_item' => [
            'title' => 'name',
            'price' => 'unit_price',
            'quantity' => 'quantity',
            'sku' => 'sku',
            'variant_title' => 'variant_name',
        ],
    ],
];
