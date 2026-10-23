<?php

namespace App\Http\Controllers;

use App\Models\DraftOrder;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\OrderManager;
use Chattrn\Connector\Http\Controllers\ChattrnApiController;
use Chattrn\Connector\Support\ModelMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChattrnCommerceController extends ChattrnApiController
{
    public function order(Request $request, string $id): JsonResponse
    {
        return response()->json(['order' => $this->serializeOrder(Order::findOrFail($id))])->header('X-Chattrn-API-Version', config('chattrn.api_version'));
    }

    public function orders(Request $request): JsonResponse
    {
        $query = Order::query()->latest('id');
        if ($request->filled('name')) {
            $query->where('reference', ltrim($request->string('name')->toString(), '#'));
        }
        $orders = $query->limit(min(250, max(1, (int) $request->query('limit', 50))))->get();

        return response()->json(['orders' => $orders->map(fn ($order) => $this->serializeOrder($order))])->header('X-Chattrn-API-Version', config('chattrn.api_version'));
    }

    public function customerOrders(Request $request, string $id): JsonResponse
    {
        return response()->json(['orders' => Order::where('customer_id', $id)->latest('id')->limit(250)->get()->map(fn ($order) => $this->serializeOrder($order))])->header('X-Chattrn-API-Version', config('chattrn.api_version'));
    }

    private function serializeOrder(Order $order): array
    {
        $result = ModelMap::toOrder($order);
        $money = fn (int $value): string => number_format($value, 2, '.', '');
        $result['subtotal_price'] = $money($order->subtotal);
        $result['total_discounts'] = $money($order->discount_total);
        $result['total_tax'] = $money($order->tax_total);
        $result['financial_status'] = $order->payment_status === 'unpaid' ? 'pending' : $order->payment_status;
        $result['fulfillment_status'] = match ($order->fulfillment_status) {
            'unfulfilled' => null, 'partially_fulfilled' => 'partial', default => $order->fulfillment_status
        };
        $result['customer']['id'] = $order->customer_id;
        $result['shipping_address'] += ['city' => $order->district, 'zip' => $order->postal_code, 'country_code' => $order->country_code];
        $result['shipping_lines'] = [['title' => $order->fulfillment, 'price' => $money($order->delivery_fee)]];
        $result['fulfillments'] = $order->shipments()->get()->toArray();
        $items = $order->items()->get()->keyBy('id');
        $result['line_items'] = collect($result['line_items'])->map(function ($line) use ($order, $items) {
            $item = $items->get($line['id']);

            return $line + ['product_id' => $item->product_id, 'variant_id' => $item->variant_id, 'fulfillable_quantity' => $order->status === 'cancelled' ? 0 : $item->quantity - $item->fulfilled_quantity, 'returned_quantity' => $item->returned_quantity];
        })->all();

        return $result;
    }

    public function operate(Request $request, string $id, string $action): JsonResponse
    {
        $order = Order::findOrFail($id);
        abort_unless(in_array($action, ['edit', 'fulfill', 'shipment_update', 'payment', 'refund', 'return', 'return_status', 'note', 'archive']), 404);
        $rules = app(OrderOperationsController::class)->rules($action);
        if ($request->operation_key && $order->events()->where('operation_key', $request->operation_key)->exists() && in_array($action, ['payment', 'refund'])) {
            $rules['reference'] = 'required|string|max:120';
        }
        $data = $request->validate($rules);
        $order = app(OrderManager::class)->apply($order, $action, $data, 'chattrn');

        return response()->json(['order' => $this->serializeOrder($order), 'revision' => $order->revision])->header('X-Chattrn-API-Version', config('chattrn.api_version'));
    }

    public function updateOrder(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['order' => 'required|array', 'order.email' => 'nullable|email|max:200', 'order.phone' => 'nullable|string|max:40', 'order.customer_name' => 'nullable|string|max:100', 'order.shipping_address' => 'nullable|array:address1,address2,city,zip,country_code', 'order.shipping_address.*' => 'nullable|string|max:500', 'order.revision' => 'required|integer|min:1', 'order.operation_key' => 'required|uuid']);
        $input = $data['order'];
        $payload = collect($input)->only(['email', 'phone', 'customer_name', 'revision', 'operation_key'])->all();
        if (isset($input['shipping_address'])) {
            $address = $input['shipping_address'];
            $payload += ['address' => trim(($address['address1'] ?? '').' '.($address['address2'] ?? '')), 'district' => $address['city'] ?? null, 'postal_code' => $address['zip'] ?? null, 'country_code' => $address['country_code'] ?? 'BD'];
        }
        $request->replace($payload + ['reason' => 'Order details updated through Chattrn']);

        return $this->operate($request, $id, 'edit');
    }

    public function cancelOrder(Request $request, string $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $data = $request->validate(['operation_key' => 'nullable|uuid', 'reason' => 'nullable|string|max:500']);
        $data['reason'] = $data['reason'] ?? 'Cancellation requested through Chattrn';
        $data['operation_key'] = $data['operation_key'] ?? (string) Str::uuid();
        $order = app(OrderManager::class)->apply($order, 'cancel', $data, 'chattrn');

        return response()->json(['order' => $this->serializeOrder($order)])->header('X-Chattrn-API-Version', config('chattrn.api_version'));
    }

    public function orderFulfillments(Request $request, string $id): JsonResponse
    {
        return response()->json(['fulfillments' => Order::findOrFail($id)->shipments()->get()])->header('X-Chattrn-API-Version', config('chattrn.api_version'));
    }

    public function createDraftOrder(Request $request): JsonResponse
    {
        [$payload, $quote] = $this->draftPayload($request);
        $draft = DraftOrder::create(['reference' => (string) Str::uuid(), 'payload' => $payload, 'total' => $quote['total']]);

        return $this->draftResponse($draft);
    }

    public function updateDraftOrder(Request $request, string $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $draft = DraftOrder::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($draft->order_id, 422, 'Completed drafts cannot be changed.');
            [$payload, $quote] = $this->draftPayload($request);
            $draft->update(['payload' => $payload, 'total' => $quote['total']]);

            return $this->draftResponse($draft);
        });
    }

    public function completeDraftOrder(Request $request, string $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $draft = DraftOrder::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $draft->order_id) {
                $request->replace($draft->payload + ['idempotency_key' => $draft->reference]);
                $result = app(StoreController::class)->order($request)->getData(true);
                $draft->update(['order_id' => Order::where('reference', $result['reference'])->firstOrFail()->id]);
            }

            return $this->draftResponse($draft);
        });
    }

    private function draftResponse(DraftOrder $draft): JsonResponse
    {
        return response()->json(['draft_order' => ['id' => $draft->id, 'order_id' => $draft->order_id, 'status' => $draft->order_id ? 'completed' : 'open', 'total_price' => $draft->order_id ? Order::findOrFail($draft->order_id)->total : $draft->total, 'currency' => 'BDT', 'line_items' => $draft->payload['items']]])->header('X-Chattrn-API-Version', config('chattrn.api_version'));
    }

    private function draftPayload(Request $request): array
    {
        $input = $request->validate(['draft_order' => 'required|array', 'draft_order.customer_name' => 'nullable|string|max:100', 'draft_order.phone' => 'nullable|string|max:40', 'draft_order.email' => 'nullable|email|max:200', 'draft_order.address' => 'nullable|string|max:1000', 'draft_order.fulfillment' => 'nullable|in:delivery,pickup', 'draft_order.country_code' => 'nullable|string|size:2', 'draft_order.district' => 'nullable|string|max:100', 'draft_order.postal_code' => 'nullable|string|max:30', 'draft_order.discount_code' => 'nullable|string|max:40', 'draft_order.payment_method' => 'nullable|in:cod,bkash,nagad,rocket,bank,other_mfs', 'draft_order.location_id' => 'nullable|integer|exists:outlets,id', 'draft_order.outlet_id' => 'nullable|integer|exists:outlets,id', 'draft_order.shipping_address' => 'nullable|array:first_name,last_name,phone,address1,address2,city,zip,country_code', 'draft_order.shipping_address.*' => 'nullable|string|max:500', 'draft_order.line_items' => 'required|array|min:1|max:50', 'draft_order.line_items.*.variant_id' => 'required|integer|distinct|exists:product_variants,id', 'draft_order.line_items.*.quantity' => 'required|integer|min:1|max:20'])['draft_order'];
        $address = $input['shipping_address'] ?? [];
        $items = collect($input['line_items'])->map(fn ($i) => ['product_id' => ProductVariant::findOrFail($i['variant_id'])->product_id, 'variant_id' => $i['variant_id'], 'quantity' => $i['quantity']])->all();
        $payload = ['outlet_id' => $input['location_id'] ?? $input['outlet_id'] ?? config('chattrn.store.primary_location_id'), 'items' => $items, 'fulfillment' => $input['fulfillment'] ?? 'delivery', 'country_code' => $input['country_code'] ?? $address['country_code'] ?? 'BD', 'district' => $input['district'] ?? $address['city'] ?? null, 'postal_code' => $input['postal_code'] ?? $address['zip'] ?? null, 'customer_name' => $input['customer_name'] ?? trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? '')), 'phone' => $input['phone'] ?? $address['phone'] ?? '', 'email' => $input['email'] ?? null, 'address' => $input['address'] ?? trim(($address['address1'] ?? '').' '.($address['address2'] ?? '')), 'payment_method' => $input['payment_method'] ?? 'cod', 'discount_code' => $input['discount_code'] ?? null];
        $quoteRequest = Request::create('/api/v1/quote', 'POST', $payload);

        return [$payload, app(StoreController::class)->quote($quoteRequest)];
    }
}
