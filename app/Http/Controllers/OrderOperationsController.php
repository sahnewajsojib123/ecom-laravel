<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Setting;
use App\Services\OrderManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderOperationsController extends Controller
{
    public function show(Order $order, OrderManager $manager): Order
    {
        return $manager->view($order);
    }

    public function rules(string $action): array
    {
        $lines = ['items' => 'required|array|min:1|max:50', 'items.*.order_item_id' => 'required|integer|distinct', 'items.*.quantity' => 'required|integer|min:1|max:1000'];

        return ['revision' => 'required|integer|min:1', 'operation_key' => 'required|uuid'] + match ($action) {
            'edit' => ['reason' => 'required|string|max:500', 'customer_name' => 'sometimes|required|string|max:100', 'phone' => ['sometimes', 'required', 'regex:/^\+?[0-9 ()-]{8,20}$/'], 'email' => 'sometimes|nullable|email|max:200', 'address' => 'sometimes|required|string|max:1000', 'country_code' => 'sometimes|required|string|size:2', 'district' => 'sometimes|nullable|string|max:100', 'postal_code' => 'sometimes|nullable|string|max:30', 'delivery_fee' => 'sometimes|integer|min:0|max:1000000', 'discount_total' => 'sometimes|integer|min:0|max:100000000', 'items' => 'sometimes|required|array|min:1|max:50', 'items.*.variant_id' => 'required|integer|distinct|exists:product_variants,id', 'items.*.quantity' => 'required|integer|min:0|max:1000'],
            'exchange' => ['return_id' => 'required|integer', 'reason' => 'required|string|max:500', 'items' => 'required|array|min:1|max:50', 'items.*.variant_id' => 'required|integer|distinct|exists:product_variants,id', 'items.*.quantity' => 'required|integer|min:1|max:20'],
            'cancel' => ['reason' => 'required|string|max:500'],
            'status' => ['status' => 'required|in:confirmed,processing,completed,cancelled'],
            'shipment_update' => ['shipment_id' => 'required|integer', 'status' => 'required|in:shipped,in_transit,delivered', 'carrier' => 'nullable|string|max:100', 'tracking_number' => 'nullable|string|max:200', 'tracking_url' => 'nullable|url:http,https|max:2000'],
            'fulfill' => $lines + ['carrier' => 'nullable|string|max:100', 'tracking_number' => 'nullable|string|max:200', 'tracking_url' => 'nullable|url:http,https|max:2000'],
            'return' => $lines + ['reason' => 'required|string|max:1000'],
            'return_status' => ['return_id' => 'required|integer', 'status' => 'required|in:approved,rejected,received', 'restock' => 'required|boolean', 'reason' => 'required|string|max:500'],
            'payment', 'refund' => ['amount' => 'required|integer|min:1|max:100000000', 'method' => ['required', Rule::in(['cash', 'bkash', 'nagad', 'rocket', 'bank', 'other_mfs'])], 'reference' => ['required', 'string', 'max:120', Rule::unique('payment_records')->where('method', request('method'))], 'note' => 'required|string|max:500', 'confirmed' => 'required|accepted'],
            'payment_submitted' => ['method' => 'required|in:bkash,nagad,rocket,bank,other_mfs', 'reference' => 'required|string|max:120', 'amount' => 'required|integer|min:1|max:100000000'],
            'note' => ['note' => 'required|string|max:2000'],
            'archive' => ['archived' => 'required|boolean'],
            default => abort(404),
        };
    }

    public function action(Request $request, Order $order, string $action, OrderManager $manager): Order
    {
        // Unique settlement references are also enforced under the order transaction.
        $rules = $this->rules($action);
        if ($request->operation_key && $order->events()->where('operation_key', $request->operation_key)->exists() && in_array($action, ['payment', 'refund'])) {
            $rules['reference'] = 'required|string|max:120';
        }
        $data = $request->validate($rules);

        try {
            return $manager->apply($order, $action, $data, 'admin:'.$request->user()->id);
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['reference' => 'This transaction reference has already been recorded.']);
        }
    }

    public function customerShow(Request $request, Order $order, OrderManager $manager): array
    {
        $this->authorizeCustomer($request, $order);
        $order = $manager->view($order);
        $result = $order->only(['reference', 'revision', 'status', 'payment_status', 'fulfillment_status', 'customer_name', 'phone', 'email', 'address', 'country_code', 'district', 'postal_code', 'fulfillment', 'subtotal', 'discount_total', 'tax_total', 'delivery_fee', 'total', 'balance_due', 'refund_due', 'created_at']);
        $result['items'] = $order->items->toArray();
        $result['shipments'] = $order->shipments->toArray();
        $result['returns'] = $order->returns->toArray();
        $result['actions'] = collect(['edit', 'cancel', 'payment_submitted', 'return'])->mapWithKeys(fn ($action) => [$action => URL::temporarySignedRoute('order.customer.action', now()->addHours(2), ['order' => $order->reference, 'action' => $action])]);
        $result['payment_submissions'] = $order->events->where('action', 'payment_submitted')->map(fn ($event) => collect($event->details)->only(['method', 'reference', 'amount'])->all() + ['verified' => $order->payments->where('type', 'payment')->where('method', $event->details['method'])->where('reference', $event->details['reference'])->isNotEmpty()])->values();
        $result['manual_payments'] = Setting::whereIn('key', ['payment_bkash', 'payment_nagad', 'payment_rocket', 'payment_bank', 'payment_other_mfs'])->pluck('value', 'key');

        return $result;
    }

    public function customerAction(Request $request, Order $order, string $action, OrderManager $manager): array
    {
        $this->authorizeCustomer($request, $order);
        abort_unless(in_array($action, ['edit', 'cancel', 'payment_submitted', 'return']), 404);
        $data = $request->validate($this->rules($action));
        if ($action === 'edit') {
            abort_unless(in_array($order->status, ['pending', 'confirmed']) && $order->fulfillment_status === 'unfulfilled', 422, 'Contact the store: this order is already being processed.');
            abort_if(isset($data['items']) || isset($data['delivery_fee']) || isset($data['discount_total']), 422, 'Contact the store to change products, quantities or charges.');
        }
        if ($action === 'cancel') {
            abort_unless(in_array($order->status, ['pending', 'confirmed']) && $order->fulfillment_status === 'unfulfilled', 422, 'Contact the store to cancel a processing order.');
        }
        $manager->apply($order, $action, $data, 'customer:'.($request->user()?->id ?? 'signed-link'));

        return ['ok' => true];
    }

    private function authorizeCustomer(Request $request, Order $order): void
    {
        abort_unless($request->hasValidSignature() || ($request->user() && ! $request->user()->is_admin && $order->user_id === $request->user()->id), 403);
    }
}
