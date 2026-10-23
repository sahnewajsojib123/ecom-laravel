<?php

namespace Tests\Feature;

use App\Models\LoginCode;
use App\Models\MessageDelivery;
use App\Models\SavedCart;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\CommerceOperationsTestCase;

class CustomerAccountTest extends CommerceOperationsTestCase
{
    public function test_email_sign_in_is_disabled_until_configured(): void
    {
        config(['commerce.email_enabled' => false]);
        $this->postJson('/api/v1/account/code', ['email' => 'buyer@example.com'])->assertStatus(503);
        $this->assertDatabaseCount('login_codes', 0);
        $this->get('/account')->assertOk()->assertSee('data-sign-in-enabled="0"', false);
    }

    public function test_one_time_email_verification_links_only_orders_for_verified_email(): void
    {
        config(['commerce.email_enabled' => true]);
        $shop = $this->shop();
        $order = $this->placed($shop);
        LoginCode::create(['email' => 'buyer@example.com', 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10)]);
        $this->postJson('/api/v1/account/verify', ['email' => 'buyer@example.com', 'code' => '123456'])->assertOk();
        $this->getJson('/api/v1/account')->assertOk()->assertJsonPath('orders.0.reference', $order->reference);
        $this->assertNotNull($order->fresh()->user_id);
        $this->postJson('/api/v1/account/verify', ['email' => 'buyer@example.com', 'code' => '123456'])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/account')->assertJsonCount(0, 'orders');
    }

    public function test_expired_and_bruteforced_codes_cannot_authenticate(): void
    {
        config(['commerce.email_enabled' => true]);
        $code = LoginCode::create(['email' => 'buyer@example.com', 'code_hash' => Hash::make('123456'), 'expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/account/verify', ['email' => $code->email, 'code' => '123456'])->assertUnprocessable();
        $code->update(['expires_at' => now()->addMinutes(10), 'attempts' => 5]);
        $this->postJson('/api/v1/account/verify', ['email' => $code->email, 'code' => '123456'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_customer_code_cannot_log_into_an_admin_account(): void
    {
        config(['commerce.email_enabled' => true]);
        $admin = User::factory()->create(['is_admin' => true]);
        LoginCode::create(['email' => $admin->email, 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10)]);
        $this->postJson('/api/v1/account/verify', ['email' => $admin->email, 'code' => '123456'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_signed_order_page_works_and_customer_response_does_not_expose_internal_notes(): void
    {
        $order = $this->placed($this->shop());
        $order->events()->create(['action' => 'note', 'actor' => 'admin:1', 'details' => ['note' => 'Private staff note']]);
        $page = URL::temporarySignedRoute('order.status', now()->addHour(), ['order' => $order->reference]);
        $this->get($page)->assertOk();
        $this->get('/orders/'.$order->reference)->assertForbidden();
        $url = URL::temporarySignedRoute('order.customer.show', now()->addHour(), ['order' => $order->reference]);
        $this->getJson($url)->assertOk()->assertJsonMissingPath('events')->assertJsonMissingPath('idempotency_key')->assertJsonPath('reference', $order->reference);
    }

    public function test_abandoned_cart_reminder_requires_consent_and_does_not_reserve_stock(): void
    {
        $shop = $this->shop();
        $payload = $this->payload($shop);
        $this->postJson('/api/v1/saved-cart', $payload + ['consent' => false])->assertUnprocessable();
        $this->postJson('/api/v1/saved-cart', $payload + ['consent' => true])->assertOk();
        $this->assertSame(10, $shop[3]->fresh()->quantity);
        SavedCart::query()->update(['updated_at' => now()->subHours(3)]);
        config(['commerce.email_enabled' => false]);
        $this->artisan('commerce:deliver-messages')->assertSuccessful();
        $this->artisan('commerce:deliver-messages')->assertSuccessful();
        $this->assertDatabaseCount('message_deliveries', 1);
        $this->assertSame('pending', MessageDelivery::first()->status);
        $cart = SavedCart::first();
        $url = URL::temporarySignedRoute('cart.recover', now()->addHour(), ['cart' => $cart->id]);
        $this->get($url)->assertRedirect('/');
        $this->getJson('/api/v1/saved-cart')->assertOk()->assertJsonPath('cart.outlet_id', $shop[0]->id);
        $this->getJson('/api/v1/saved-cart')->assertJsonPath('cart', null);
    }

    public function test_order_email_is_not_marked_sent_when_delivery_is_disabled(): void
    {
        config(['commerce.email_enabled' => false]);
        $this->placed($this->shop());
        $this->artisan('commerce:deliver-messages')->assertSuccessful();
        $this->assertSame('pending', MessageDelivery::first()->status);
        config(['commerce.email_enabled' => true]);
        Mail::shouldReceive('raw')->once()->andReturnNull();
        $this->artisan('commerce:deliver-messages')->assertSuccessful();
        $this->assertSame('sent', MessageDelivery::first()->fresh()->status);
    }
}
