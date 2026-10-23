<?php

namespace App\Services;

use App\Http\Controllers\StoreController;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderManager
{
    public function view(Order $order): Order
    {
        $order->load(['items', 'outlet', 'events' => fn ($q) => $q->orderByDesc('id'), 'shipments', 'payments', 'returns']);
        $paid = (int) $order->payments->where('type', 'payment')->sum('amount');
        $refunded = (int) $order->payments->where('type', 'refund')->sum('amount');
        $items = $order->items;
        $returnedSubtotal = (int) $items->sum(fn ($i) => $i->returned_quantity * $i->unit_price);
        $remainingSubtotal = $order->status === 'cancelled' ? (int) $items->sum(fn ($i) => max(0, $i->fulfilled_quantity - $i->returned_quantity) * $i->unit_price) : $order->subtotal - $returnedSubtotal;
        $target = $order->subtotal > 0 ? (int) round($remainingSubtotal * ($order->subtotal - $order->discount_total + $order->tax_total) / $order->subtotal) : 0;
        $target += $remainingSubtotal > 0 || $order->status !== 'cancelled' ? $order->delivery_fee : 0;
        $order->setAttribute('paid_total', $paid);
        $order->setAttribute('refunded_total', $refunded);
        $order->setAttribute('balance_due', max(0, $target - $paid + $refunded));
        $order->setAttribute('refund_due', max(0, $paid - $refunded - $target));

        return $order;
    }

    public function apply(Order $order, string $action, array $data, string $actor): Order
    {
        return DB::transaction(function () use ($order, $action, $data, $actor) {
            Outlet::whereKey($order->outlet_id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $key = $data['operation_key'] ?? null;
            $hash = hash('sha256', json_encode([$action, $data]));
            if ($key && ($event = $order->events()->where('operation_key', $key)->first())) {
                abort_unless(hash_equals($event->request_hash, $hash), 409, 'This operation key was already used for another request.');

                return $this->view($order);
            }
            if (isset($data['revision'])) {
                abort_unless($order->revision === (int) $data['revision'], 409, 'The order changed. Reload it before saving.');
            }
            $details = $data;
            unset($details['operation_key'], $details['revision']);
            switch ($action) {
                case 'edit': $details = $this->edit($order, $data);
                    break;
                case 'cancel': $this->cancel($order);
                    break;
                case 'status': $this->status($order, $data['status']);
                    break;
                case 'shipment_update': $this->updateShipment($order, $data);
                    break;
                case 'fulfill': $this->fulfill($order, $data);
                    break;
                case 'payment': case 'refund': $this->settle($order, $data, $action);
                    break;
                case 'payment_submitted': $this->submitPayment($order, $data);
                    break;
                case 'return': $this->requestReturn($order, $data);
                    break;
                case 'exchange': $details = $this->exchange($order, $data);
                    break;
                case 'return_status': $this->returnStatus($order, $data);
                    break;
                case 'archive': $order->archived_at = ($data['archived'] ?? true) ? now() : null;
                    break;
                case 'note': break;
                default: abort(422, 'Unsupported order action.');
            }
            $order->revision++;
            $order->save();
            $order->events()->create(['action' => $action, 'actor' => $actor, 'operation_key' => $key, 'request_hash' => $hash, 'details' => $details]);
            if ($action !== 'note' && $action !== 'archive') {
                app(CommerceMessages::class)->order($order, str_replace('_', ' ', ucfirst($action)));
            }

            return $this->view($order->fresh());
        }, 3);
    }

    private function edit(Order $order, array $data): array
    {
        abort_if($order->status === 'cancelled', 422, 'Cancelled orders cannot be edited.');
        $before = $order->only(['customer_name', 'phone', 'email', 'address', 'district', 'postal_code', 'country_code', 'subtotal', 'total', 'delivery_fee', 'discount_total', 'tax_total']);
        $before['items'] = $order->items()->get()->toArray();
        $addressChanged = collect(['address', 'district', 'postal_code', 'country_code'])->contains(fn ($key) => array_key_exists($key, $data) && $data[$key] !== $order->$key);
        abort_if($addressChanged && $order->items()->where('fulfilled_quantity', '>', 0)->exists(), 422, 'The shipping address cannot change after fulfillment starts.');
        $order->fill(collect($data)->only(['customer_name', 'phone', 'email', 'address', 'country_code', 'district', 'postal_code'])->all());
        if (isset($data['items'])) {
            $existing = $order->items()->get()->keyBy('variant_id');
            $desired = collect($data['items'])->keyBy('variant_id');
            abort_unless($desired->count() === count($data['items']), 422, 'Each variant must appear once.');
            foreach ($existing as $variantId => $line) {
                $quantity = (int) ($desired->get($variantId)['quantity'] ?? 0);
                abort_if($quantity < $line->fulfilled_quantity, 422, 'Fulfilled quantities cannot be removed. Use a return.');
                $this->adjustStock($order, (int) $variantId, $quantity - $line->quantity);
                if ($quantity === 0) {
                    $line->delete();
                } else {
                    $line->update(['quantity' => $quantity]);
                }
            }
            foreach ($desired as $variantId => $line) {
                if ($existing->has($variantId) || (int) $line['quantity'] === 0) {
                    continue;
                }
                $stock = $this->adjustStock($order, (int) $variantId, (int) $line['quantity']);
                $order->items()->create(['product_id' => $stock->product_id, 'variant_id' => $variantId, 'name' => $stock->product->name, 'variant_name' => $stock->variant->name, 'sku' => $stock->variant->sku, 'quantity' => $line['quantity'], 'unit_price' => $stock->price]);
            }
            abort_unless($order->items()->exists(), 422, 'An order must contain at least one item. Cancel it instead.');
        }
        $order->subtotal = (int) $order->items()->selectRaw('SUM(quantity * unit_price) AS total')->value('total');
        if ($addressChanged) {
            $quote = app(CheckoutPricing::class)->totals($order->subtotal, ['outlet_id' => $order->outlet_id, 'country_code' => $order->country_code, 'district' => $order->district, 'fulfillment' => $order->fulfillment]);
            $order->tax_basis_points = $quote['tax_basis_points'];
        }
        if (isset($data['delivery_fee'])) {
            $order->delivery_fee = $order->fulfillment === 'pickup' ? 0 : $data['delivery_fee'];
        }
        $order->discount_total = min($order->subtotal, $data['discount_total'] ?? $order->discount_total);
        $order->tax_total = (int) round(($order->subtotal - $order->discount_total) * $order->tax_basis_points / 10000);
        $order->total = $order->subtotal - $order->discount_total + $order->tax_total + $order->delivery_fee;
        $this->paymentStatus($order);
        $this->fulfillmentStatus($order);
        if ($order->status === 'completed' && $order->fulfillment_status !== 'fulfilled') {
            $order->status = 'processing';
            $order->archived_at = null;
        }

        return ['reason' => $data['reason'], 'before' => $before, 'after' => $order->only(array_keys($before)) + ['items' => $order->items()->get()->toArray()]];
    }

    private function adjustStock(Order $order, int $variantId, int $delta): Inventory
    {
        $stock = Inventory::with('product', 'variant')->where('outlet_id', $order->outlet_id)->where('variant_id', $variantId)->lockForUpdate()->first();
        if (! $stock || ($delta > 0 && (! $stock->product->active || ! $stock->variant->active || $stock->quantity < $delta))) {
            throw ValidationException::withMessages(['items' => 'A variant is unavailable or has insufficient outlet stock.']);
        }
        $stock->quantity -= $delta;
        $stock->save();

        return $stock;
    }

    private function cancel(Order $order): void
    {
        abort_if(in_array($order->status, ['cancelled', 'completed']), 422, 'This order cannot be cancelled.');
        foreach ($order->items()->orderBy('variant_id')->get() as $line) {
            $this->adjustStock($order, (int) $line->variant_id, -($line->quantity - $line->fulfilled_quantity));
        }
        $order->status = 'cancelled';
        $order->cancelled_at = now();
    }

    private function status(Order $order, string $status): void
    {
        $allowed = ['pending' => ['confirmed', 'cancelled'], 'confirmed' => ['processing', 'cancelled'], 'processing' => ['completed', 'cancelled'], 'completed' => [], 'cancelled' => []];
        abort_unless(in_array($status, $allowed[$order->status] ?? []), 422, 'This order status transition is not allowed.');
        if ($status === 'cancelled') {
            $this->cancel($order);

            return;
        }
        if ($status === 'completed') {
            $lines = $order->items()->get()->map(fn ($i) => ['order_item_id' => $i->id, 'quantity' => $i->quantity - $i->fulfilled_quantity])->filter(fn ($i) => $i['quantity'] > 0)->values()->all();
            if ($lines) {
                $this->fulfill($order, ['items' => $lines]);
            }
        }
        $order->status = $status;
    }

    private function fulfill(Order $order, array $data): void
    {
        abort_if($order->status === 'cancelled', 422, 'Cancelled orders cannot be fulfilled.');
        foreach ($data['items'] as $line) {
            $item = $order->items()->whereKey($line['order_item_id'])->firstOrFail();
            abort_if($line['quantity'] > $item->quantity - $item->fulfilled_quantity, 422, 'Quantity exceeds the remaining fulfillment.');
            $item->increment('fulfilled_quantity', $line['quantity']);
        }
        $order->shipments()->create(collect($data)->only(['items', 'carrier', 'tracking_number', 'tracking_url'])->all());
        $this->fulfillmentStatus($order);
        $order->status = $order->fulfillment_status === 'fulfilled' ? 'completed' : 'processing';
    }

    private function updateShipment(Order $order, array $data): void
    {
        $shipment = $order->shipments()->whereKey($data['shipment_id'])->firstOrFail();
        $shipment->update(collect($data)->only(['carrier', 'tracking_number', 'tracking_url', 'status'])->all());
    }

    private function fulfillmentStatus(Order $order): void
    {
        $items = $order->items()->get();
        $fulfilled = $items->sum('fulfilled_quantity');
        $order->fulfillment_status = $fulfilled === 0 ? 'unfulfilled' : ($fulfilled === $items->sum('quantity') ? 'fulfilled' : 'partially_fulfilled');
    }

    private function submitPayment(Order $order, array $data): void
    {
        abort_if($order->status === 'cancelled', 422, 'Cancelled orders cannot accept payment references.');
        abort_unless(Setting::where('key', 'payment_'.$data['method'])->where('value', '!=', '')->exists(), 422, 'Contact the store for payment instructions.');
        abort_unless(in_array($data['method'], ['bkash', 'nagad', 'rocket', 'bank', 'other_mfs']), 422, 'Choose a supported manual method.');
        abort_if($order->events()->where('action', 'payment_submitted')->where('details->reference', $data['reference'])->exists(), 422, 'This reference has already been submitted.');
    }

    private function settle(Order $order, array $data, string $type): void
    {
        $paid = (int) $order->payments()->where('type', 'payment')->sum('amount');
        $refunded = (int) $order->payments()->where('type', 'refund')->sum('amount');
        if ($type === 'payment') {
            abort_if($order->status === 'cancelled' || $data['amount'] > $this->view($order->fresh())->balance_due, 422, 'Payment exceeds the balance due or the order is cancelled.');
        } else {
            abort_if($data['amount'] > $paid - $refunded, 422, 'Refund exceeds the amount collected.');
        }
        $order->payments()->create(['type' => $type, 'amount' => $data['amount'], 'method' => $data['method'], 'reference' => $data['reference'], 'note' => $data['note']]);
        $this->paymentStatus($order);
    }

    private function paymentStatus(Order $order): void
    {
        $paid = (int) $order->payments()->where('type', 'payment')->sum('amount');
        $refunded = (int) $order->payments()->where('type', 'refund')->sum('amount');
        $order->payment_status = $refunded > 0 ? ($refunded === $paid ? 'refunded' : 'partially_refunded') : ($paid === 0 ? 'unpaid' : ($paid >= $order->total ? 'paid' : 'partially_paid'));
    }

    private function requestReturn(Order $order, array $data): void
    {
        foreach ($data['items'] as $line) {
            $item = $order->items()->whereKey($line['order_item_id'])->firstOrFail();
            $reserved = $order->returns()->whereIn('status', ['requested', 'approved'])->get()->sum(fn ($r) => collect($r->items)->where('order_item_id', $item->id)->sum('quantity'));
            abort_if($line['quantity'] > $item->fulfilled_quantity - $item->returned_quantity - $reserved, 422, 'Return quantity exceeds eligible fulfilled items.');
        }
        $order->returns()->create(['items' => $data['items'], 'reason' => $data['reason']]);
    }

    private function exchange(Order $order, array $data): array
    {
        $return = $order->returns()->whereKey($data['return_id'])->firstOrFail();
        abort_unless($return->status === 'received', 422, 'Receive the returned items before creating an exchange.');
        abort_if($order->events()->where('action', 'exchange')->where('details->return_id', $return->id)->exists(), 422, 'This return already has an exchange order.');
        $items = collect($data['items'])->map(function ($line) {
            $variant = ProductVariant::findOrFail($line['variant_id']);

            return ['product_id' => $variant->product_id, 'variant_id' => $variant->id, 'quantity' => $line['quantity']];
        })->all();
        $payload = $order->only(['outlet_id', 'customer_name', 'phone', 'email', 'address', 'country_code', 'district', 'postal_code', 'fulfillment']);
        $request = Request::create('/api/v1/orders', 'POST', $payload + ['items' => $items, 'idempotency_key' => $data['operation_key']]);
        $request->setLaravelSession(request()->session());
        $result = app(StoreController::class)->order($request)->getData(true);
        Order::where('reference', $result['reference'])->update(['exchange_order_id' => $order->id, 'user_id' => $order->user_id]);

        return ['return_id' => $return->id, 'exchange_reference' => $result['reference'], 'reason' => $data['reason'], 'note' => 'A separate exchange order was created. Record refunds and replacement payments separately after manual settlement.'];
    }

    private function returnStatus(Order $order, array $data): void
    {
        $return = $order->returns()->whereKey($data['return_id'])->lockForUpdate()->firstOrFail();
        $allowed = ['requested' => ['approved', 'rejected'], 'approved' => ['received', 'rejected']];
        abort_unless(in_array($data['status'], $allowed[$return->status] ?? []), 422, 'This return transition is not allowed.');
        if ($data['status'] === 'received') {
            foreach ($return->items as $line) {
                $item = $order->items()->whereKey($line['order_item_id'])->firstOrFail();
                abort_if($line['quantity'] > $item->fulfilled_quantity - $item->returned_quantity, 422, 'Return quantity exceeds eligible items.');
                $item->increment('returned_quantity', $line['quantity']);
                if ($data['restock']) {
                    $this->adjustStock($order, (int) $item->variant_id, -$line['quantity']);
                }
            }
            $return->restocked = $data['restock'];
        }
        $return->status = $data['status'];
        $return->save();
    }
}
