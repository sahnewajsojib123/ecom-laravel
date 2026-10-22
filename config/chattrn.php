<?php

// Rewritten by `php artisan chattrn:map`. See vendor/chattrn/connector/config/chattrn.php
// for what each key means.
return [
    'route_prefix' => '/chattrn/api/2026-10',
    'api_version' => '2026-10',
    'access_token' => env('CHATTRN_ACCESS_TOKEN'),
    'backend_url' => env('CHATTRN_BACKEND_URL', 'https://api.chattrn.com'),
    'connection_token' => env('CHATTRN_TOKEN'),
    'store' => [
        'name' => 'Nova Tech',
        'domain' => 'http://127.0.0.1:8000',
        'currency' => 'BDT',
        'country_code' => 'BD',
        'delivery_mode' => 'zone',
        'product_url_template' => null,
        'cart_url_template' => null,
        'primary_location_id' => '1',
    ],
    'models' => [
        'product' => 'App\\Models\\Product',
        'variant' => 'App\\Models\\ProductVariant',
        'order' => 'App\\Models\\Order',
        'customer' => null,
        'location' => 'App\\Models\\Outlet',
    ],
    'inventory' => [
        'model' => 'App\\Models\\Inventory',
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
            'title' => 'title',
            'handle' => 'slug',
            'vendor' => null,
            'status' => null,
            'image_url' => 'image_url',
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
            'number' => 'number',
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
