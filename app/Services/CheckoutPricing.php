<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\ShippingZone;
use Illuminate\Validation\ValidationException;

class CheckoutPricing
{
    public function totals(int $subtotal, array $data, bool $consume = false): array
    {
        if (($data['fulfillment'] ?? 'delivery') === 'pickup' && ! empty($data['outlet_id'])) {
            $outlet = Outlet::findOrFail($data['outlet_id']);
            $data['country_code'] = $outlet->country_code;
            $data['district'] = $outlet->district;
        }
        $zone = ShippingZone::where('active', true)->where('country_code', strtoupper($data['country_code'] ?? 'BD'))
            ->where(fn ($q) => $q->whereNull('district')->orWhere('district', mb_strtolower(trim($data['district'] ?? ''))))
            ->orderByRaw('CASE WHEN district IS NULL THEN 1 ELSE 0 END')->first();
        abort_if(! $zone && strtoupper($data['country_code'] ?? 'BD') !== 'BD', 422, 'Shipping is not configured for this country.');
        $fee = ($data['fulfillment'] ?? 'delivery') === 'pickup' ? 0 : (int) ($zone?->fee ?? Setting::where('key', 'delivery_fee')->value('value') ?? 120);
        if ($zone && $zone->free_above !== null && $subtotal >= $zone->free_above) {
            $fee = 0;
        }
        $discount = 0;
        $code = strtoupper(trim($data['discount_code'] ?? ''));
        if ($code !== '') {
            $query = Discount::where('code', $code);
            $rule = ($consume ? $query->lockForUpdate() : $query)->first();
            if (! $rule || ! $rule->active || ($rule->starts_at && $rule->starts_at->isFuture()) || ($rule->ends_at && $rule->ends_at->isPast()) || ($rule->usage_limit !== null && $rule->used_count >= $rule->usage_limit) || $subtotal < $rule->minimum_subtotal) {
                throw ValidationException::withMessages(['discount_code' => 'This discount is unavailable or its minimum order value has not been met.']);
            }
            $discount = match ($rule->type) {
                'percentage' => (int) round($subtotal * $rule->value / 100), 'fixed' => min($subtotal, $rule->value), 'free_shipping' => 0
            };
            if ($rule->type === 'free_shipping') {
                $fee = 0;
            }
            if ($consume) {
                $rule->increment('used_count');
            }
        }
        $bps = (int) ($zone?->tax_basis_points ?? 0);
        $tax = (int) round(($subtotal - $discount) * $bps / 10000);

        return ['subtotal' => $subtotal, 'discount_code' => $code ?: null, 'discount_total' => $discount, 'delivery_fee' => $fee, 'tax_basis_points' => $bps, 'tax_total' => $tax, 'total' => $subtotal - $discount + $fee + $tax];
    }
}
