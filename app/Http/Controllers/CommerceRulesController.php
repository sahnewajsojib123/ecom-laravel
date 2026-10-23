<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use App\Models\MessageDelivery;
use App\Models\Setting;
use App\Models\ShippingZone;
use App\Services\CommerceMessages;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommerceRulesController extends Controller
{
    public function index(): array
    {
        return ['discounts' => Discount::latest()->get(), 'shipping_zones' => ShippingZone::orderBy('country_code')->get(), 'messages' => MessageDelivery::select('id', 'order_id', 'recipient', 'subject', 'status', 'attempts', 'last_error', 'created_at')->latest()->limit(100)->get(), 'email_enabled' => (bool) config('commerce.email_enabled'), 'payments' => Setting::where('key', 'like', 'payment_%')->pluck('value', 'key')];
    }

    public function save(Request $request, string $type, ?int $id = null): mixed
    {
        if ($type === 'discounts') {
            $request->merge(['code' => strtoupper(trim($request->input('code', '')))]);
            $data = $request->validate(['code' => ['required', 'regex:/^[A-Z0-9_-]{2,40}$/', Rule::unique('discounts')->ignore($id)], 'type' => 'required|in:fixed,percentage,free_shipping', 'value' => 'required|integer|min:0|max:100000000', 'minimum_subtotal' => 'required|integer|min:0|max:100000000', 'usage_limit' => 'nullable|integer|min:1', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after:starts_at', 'active' => 'required|boolean']);
            abort_if($data['type'] === 'percentage' && $data['value'] > 100, 422, 'Percentage cannot exceed 100.');
            $record = $id ? Discount::findOrFail($id) : new Discount;
        } elseif ($type === 'shipping_zones') {
            $request->merge(['country_code' => strtoupper($request->input('country_code', 'BD')), 'district' => $request->filled('district') ? mb_strtolower(trim($request->district)) : null]);
            $data = $request->validate(['name' => 'required|string|max:100', 'country_code' => 'required|string|size:2', 'district' => 'nullable|string|max:100', 'fee' => 'required|integer|min:0|max:1000000', 'free_above' => 'nullable|integer|min:0|max:100000000', 'tax_basis_points' => 'required|integer|min:0|max:10000', 'active' => 'required|boolean']);
            abort_if(ShippingZone::where('country_code', $data['country_code'])->where('district', $data['district'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists(), 422, 'This destination already has a rule.');
            $record = $id ? ShippingZone::findOrFail($id) : new ShippingZone;
        } else {
            abort(404);
        }
        $record->fill($data)->save();

        return $record;
    }

    public function payments(Request $request): array
    {
        $data = $request->validate(['payment_bkash' => 'nullable|string|max:1000', 'payment_nagad' => 'nullable|string|max:1000', 'payment_rocket' => 'nullable|string|max:1000', 'payment_bank' => 'nullable|string|max:1000', 'payment_other_mfs' => 'nullable|string|max:1000']);
        foreach ($data as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
        }

        return ['ok' => true];
    }

    public function deliver(CommerceMessages $messages): array
    {
        return ['sent' => $messages->deliver(), 'enabled' => (bool) config('commerce.email_enabled')];
    }
}
