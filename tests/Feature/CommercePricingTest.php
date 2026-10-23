<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\ShippingZone;
use Tests\CommerceOperationsTestCase;

class CommercePricingTest extends CommerceOperationsTestCase
{
    public function test_authoritative_quote_and_checkout_apply_discount_shipping_and_tax(): void
    {
        $shop = $this->shop();
        ShippingZone::create(['name' => 'Dhaka', 'country_code' => 'BD', 'district' => 'dhaka', 'fee' => 60, 'tax_basis_points' => 500]);
        Discount::create(['code' => 'SAVE10', 'type' => 'percentage', 'value' => 10, 'usage_limit' => 1]);
        $payload = $this->payload($shop) + ['district' => 'Dhaka', 'discount_code' => 'save10'];
        $this->postJson('/api/v1/quote', $payload)->assertOk()->assertJsonPath('total', 1950)->assertJsonPath('tax_total', 90)->assertJsonPath('discount_total', 200);
        $this->assertSame(0, Discount::first()->used_count);
        $this->postJson('/api/v1/orders', $payload + ['expected_total' => 1950])->assertCreated()->assertJsonPath('total', 1950);
        $this->assertSame(1, Discount::first()->used_count);
        $this->postJson('/api/v1/quote', $payload)->assertUnprocessable();
    }

    public function test_stale_total_expired_code_and_shipping_destination_roll_back_checkout(): void
    {
        $shop = $this->shop();
        $this->postJson('/api/v1/orders', $this->payload($shop) + ['expected_total' => 1])->assertUnprocessable();
        $this->assertSame(10, $shop[3]->fresh()->quantity);
        Discount::create(['code' => 'EXPIRED', 'type' => 'fixed', 'value' => 500, 'ends_at' => now()->subDay()]);
        $this->postJson('/api/v1/quote', $this->payload($shop) + ['discount_code' => 'EXPIRED'])->assertUnprocessable();
        $this->postJson('/api/v1/quote', $this->payload($shop) + ['country_code' => 'US'])->assertUnprocessable();
    }

    public function test_free_shipping_minimum_and_pickup_rules(): void
    {
        $shop = $this->shop();
        Discount::create(['code' => 'FREESHIP', 'type' => 'free_shipping', 'value' => 0, 'minimum_subtotal' => 3000]);
        $payload = $this->payload($shop) + ['discount_code' => 'FREESHIP'];
        $this->postJson('/api/v1/quote', $payload)->assertUnprocessable();
        $payload['items'][0]['quantity'] = 3;
        $this->postJson('/api/v1/quote', $payload)->assertOk()->assertJsonPath('delivery_fee', 0)->assertJsonPath('total', 3000);
        $payload['fulfillment'] = 'pickup';
        unset($payload['discount_code']);
        $this->postJson('/api/v1/quote', $payload)->assertOk()->assertJsonPath('delivery_fee', 0);
    }

    public function test_rule_management_requires_admin_and_rejects_invalid_percentages(): void
    {
        $data = ['code' => 'SAVE', 'type' => 'percentage', 'value' => 200, 'minimum_subtotal' => 0, 'active' => true];
        $this->postJson('/api/v1/admin/commerce/rules/discounts', $data)->assertForbidden();
        $this->admin();
        $this->postJson('/api/v1/admin/commerce/rules/discounts', $data)->assertUnprocessable();
        $this->postJson('/api/v1/admin/commerce/rules/discounts', array_replace($data, ['value' => 10]))->assertCreated();
        $zone = ['name' => 'National', 'country_code' => 'BD', 'district' => null, 'fee' => 120, 'tax_basis_points' => 0, 'active' => true];
        $this->postJson('/api/v1/admin/commerce/rules/shipping_zones', $zone)->assertCreated();
        $this->postJson('/api/v1/admin/commerce/rules/shipping_zones', $zone)->assertUnprocessable();
    }

    public function test_unconfigured_manual_payment_methods_cannot_be_selected_at_checkout(): void
    {
        $shop = $this->shop();
        $this->postJson('/api/v1/orders', $this->payload($shop) + ['payment_method' => 'bkash'])->assertUnprocessable();
        $this->assertSame(10, $shop[3]->fresh()->quantity);
    }

    public function test_pickup_tax_uses_outlet_destination_instead_of_customer_district(): void
    {
        $shop = $this->shop();
        $shop[0]->update(['district' => 'Dhaka']);
        ShippingZone::create(['name' => 'Dhaka', 'country_code' => 'BD', 'district' => 'dhaka', 'fee' => 60, 'tax_basis_points' => 500]);
        $payload = array_replace($this->payload($shop), ['fulfillment' => 'pickup', 'district' => 'Other district']);
        $this->postJson('/api/v1/quote', $payload)->assertOk()->assertJsonPath('tax_total', 100)->assertJsonPath('delivery_fee', 0);
    }
}
