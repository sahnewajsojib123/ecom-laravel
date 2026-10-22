<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('commerce.admin_password');
        if (! $password || strlen($password) < 12) {
            throw new \RuntimeException('Set ADMIN_PASSWORD (at least 12 characters) before seeding.');
        }
        $user = User::firstOrNew(['email' => config('commerce.admin_email')]);
        $user->name = 'Store Administrator';
        $user->password = Hash::make($password);
        $user->is_admin = true;
        $user->save();
        foreach ([['Bashundhara City', 'Level 6, Bashundhara City, Panthapath, Dhaka', '01700-000001'], ['Jamuna Future Park', 'Level 4, Jamuna Future Park, Dhaka', '01700-000002'], ['Chattogram', 'GEC Circle, Chattogram', '01700-000003']] as $o) {
            Outlet::firstOrCreate(['name' => $o[0]], ['address' => $o[1], 'phone' => $o[2]]);
        }
        foreach (['Smartphones' => 'smartphone', 'Laptops' => 'laptop', 'Tablets' => 'tablet', 'Audio' => 'headphones', 'Smart watches' => 'watch', 'Accessories' => 'plug', 'Monitors' => 'monitor', 'Gaming' => 'gamepad'] as $n => $i) {
            Category::firstOrCreate(['name' => $n], ['icon' => $i]);
        }
        $base = 'https://sumashtech.sgp1.cdn.digitaloceanspaces.com/';
        $products = [
            ['Google Pixel Fold', 1, 'Google', 114999, 129999, 'card_image/None/pixel_fold_card.webp', 'A new perspective. Foldable OLED display, advanced Google camera, and all-day power.'],
            ['ASUS Vivobook 15', 2, 'ASUS', 72999, 78999, 'card_image/None/Asus_Vivobook_15_M1502NAQ.webp', 'Make every day productive with a vivid 15.6-inch display and a lightweight design.'],
            ['Anker Soundcore R50i NC', 4, 'Anker', 2499, 3390, 'card_image/1212/Anker_Soundcore_R50i_NC_-_Blue.webp', 'Your world, on mute. Adaptive noise cancellation and rich, immersive sound.'],
            ['Honor Watch X5i', 5, 'Honor', 4499, 5400, 'card_image/None/Honor_Watch_X5i_-_Black.webp', 'Meet your everyday companion. Fitness tracking, notifications and a brilliant display.'],
            ['iPad 10th generation', 3, 'Apple', 42999, 47999, 'category_cover/None/iPad_10th_gen_Wifi_blue_1.webp', 'Colorful. Capable. A versatile tablet for creativity, work and everything in between.'],
            ['Lenovo LOQ Gaming', 8, 'Lenovo', 134999, 145999, 'card_image/None/Lenovo_LOQ_15AHP9_U0Y6LrQ.webp', 'Built for your next level. Ryzen performance, RTX graphics and a fast 144Hz display.'],
            ['Logitech M235 Wireless Mouse', 6, 'Logitech', 1399, 1699, 'card_image/None/Logitech_M235_Wireless_Mouse_-_Card_Image.webp', 'Compact comfort and reliable wireless connectivity for work anywhere.'],
            ['Vention Magnetic Power Bank', 6, 'Vention', 2999, 3499, 'card_image/None/Vention_10000mAh_20W_Magnetic_Wireless_Power_Bank.webp', '10,000mAh of power that goes wherever your day takes you.'],
            ['Redmi Note 15 Pro', 1, 'Xiaomi', 34999, 37999, 'card_image/None/note15problack_-_Card.webp', 'More detail. More possibilities. A vivid display and a camera for every moment.'],
            ['HONOR CHOICE CuBuds', 4, 'Honor', 3299, 3999, 'card_image/None/HONOR_CHOICE_CuBuds.webp', 'Small size, big sound. Lightweight wireless listening for your daily soundtrack.'],
            ['HAYLOU Watch 4S', 5, 'Haylou', 4999, 5999, 'card_image/None/HAYLOU_Watch_4S_-_Card.webp', 'An everyday smartwatch with sport modes and effortless style.'],
            ['Apple Magic Keyboard', 6, 'Apple', 11999, 13999, 'card_image/None/magic_keyboard_-_Card.webp', 'A remarkably comfortable and precise typing experience.']];
        foreach ($products as $i => $p) {
            $product = Product::firstOrCreate(['sku' => 'DEMO-'.($i + 1)], ['name' => $p[0], 'category_id' => $p[1], 'brand' => $p[2], 'description' => $p[6], 'image' => $base.$p[5], 'featured' => $i < 6]);
            foreach (Outlet::all() as $o) {
                Inventory::firstOrCreate(['product_id' => $product->id, 'outlet_id' => $o->id], ['price' => $p[3] + ($o->id - 1) * 100, 'compare_price' => $p[4], 'quantity' => ($i === 0 && $o->id === 3) ? 0 : 8 + $i]);
            }
        }
        Banner::firstOrCreate(['eyebrow' => 'THE FOLDABLE EDIT'], ['title' => 'A little fold. A world of possibilities.', 'subtitle' => 'Meet Google Pixel Fold. Big ideas deserve a bigger screen.', 'button_text' => 'Discover Pixel Fold', 'product_id' => 1, 'background' => '#edf1e9']);
        foreach (['store_name' => 'NOVA TECH', 'tagline' => 'Your next upgrade starts here.', 'phone' => '09678-000000', 'announcement' => 'A better way to shop tech. Explore your nearest outlet →', 'delivery_fee' => '120', 'logo_caption' => 'TECH FOR YOUR EVERYDAY.', 'audio_label' => 'TUNE INTO YOUR WORLD', 'audio_title' => "Good sound.\nGreat company.", 'audio_image' => $base.'card_image/1212/Anker_Soundcore_R50i_NC_-_Blue.webp', 'outlet_title' => "Your tech.\nYour neighbourhood.", 'bottom_title' => "Great tech. Real people.\nRight around the corner.", 'bottom_description' => 'Experience your next upgrade at one of our outlets.'] as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
    }
}
