<?php

// Rewritten by `php artisan chattrn:map`. See vendor/chattrn/connector/config/chattrn.php
// for what each key means.
return array (
  'route_prefix' => '/chattrn/api/2026-10',
  'api_version' => '2026-10',
  'access_token' => NULL,
  'backend_url' => 'https://api.chattrn.com',
  'connection_token' => NULL,
  'store' => 
  array (
    'name' => 'Nova Tech',
    'domain' => 'http://127.0.0.1:8000',
    'currency' => 'BDT',
    'country_code' => 'BD',
    'delivery_mode' => 'zone',
    'product_url_template' => NULL,
    'cart_url_template' => NULL,
    'primary_location_id' => '1',
  ),
  'models' => 
  array (
    'product' => 'App\\Models\\Product',
    'variant' => 'App\\Models\\ProductVariant',
    'order' => 'App\\Models\\Order',
    'customer' => NULL,
    'location' => 'App\\Models\\Outlet',
  ),
  'inventory' => 
  array (
    'model' => 'App\\Models\\Inventory',
    'columns' => 
    array (
      'product_id' => 'product_id',
      'variant_id' => 'variant_id',
      'location_id' => 'outlet_id',
      'price' => 'price',
      'stock' => 'quantity',
      'compare_price' => 'compare_price',
    ),
  ),
  'columns' => 
  array (
    'product' => 
    array (
      'title' => 'title',
      'handle' => 'slug',
      'vendor' => NULL,
      'status' => NULL,
      'image_url' => 'image_url',
    ),
    'variant' => 
    array (
      'title' => 'name',
      'price' => 'price',
      'compare_at_price' => NULL,
      'sku' => 'sku',
      'stock' => 'stock',
      'options_json' => 'options',
    ),
    'order' => 
    array (
      'number' => 'number',
      'phone' => 'phone',
      'total' => 'total',
      'currency' => NULL,
      'financial_status' => 'status',
      'fulfillment_status' => NULL,
      'customer_name' => 'customer_name',
      'address' => 'address',
    ),
    'customer' => 
    array (
      'phone' => 'phone',
      'email' => 'email',
    ),
    'order_item' => 
    array (
      'title' => 'name',
      'price' => 'unit_price',
      'quantity' => 'quantity',
      'sku' => 'sku',
      'variant_title' => 'variant_name',
    ),
  ),
);
