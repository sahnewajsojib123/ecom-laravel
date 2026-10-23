<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\CommerceOperationsTestCase;

class OrderOperationsTest extends CommerceOperationsTestCase
{
    public function test_edit_adjusts_only_outlet_stock_and_audits_original_details(): void
    {
        $shop = $this->shop();
        $order = $this->placed($shop);
        $this->admin();
        $this->operation($order, 'edit', ['reason' => 'Customer request', 'address' => 'Updated address', 'items' => [['variant_id' => $shop[2]->id, 'quantity' => 3]], 'delivery_fee' => 50])->assertOk()->assertJsonPath('total', 3050);
        $this->assertSame(7, $shop[3]->fresh()->quantity);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'address' => 'Updated address']);
        $event = $order->events()->where('action', 'edit')->firstOrFail();
        $this->assertSame('Original address', $event->details['before']['address']);
        $this->operation($order, 'edit', ['reason' => 'Too many', 'items' => [['variant_id' => $shop[2]->id, 'quantity' => 30]]])->assertUnprocessable();
        $this->assertSame(7, $shop[3]->fresh()->quantity);
    }

    public function test_edits_reject_stale_revision_and_replay_is_idempotent(): void
    {
        $order = $this->placed($this->shop());
        $this->admin();
        $payload = ['revision' => 1, 'operation_key' => (string) Str::uuid(), 'reason' => 'Change', 'address' => 'New address'];
        $url = '/api/v1/admin/orders/'.$order->id.'/actions/edit';
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame(1, $order->events()->where('action', 'edit')->count());
        $this->postJson($url, array_replace($payload, ['address' => 'Different']))->assertConflict();
        $this->postJson($url, array_replace($payload, ['operation_key' => (string) Str::uuid()]))->assertConflict();
    }

    public function test_partial_fulfillment_locks_shipped_quantities_and_cancellation_restores_only_unshipped_stock(): void
    {
        $shop = $this->shop();
        $order = $this->placed($shop);
        $this->admin();
        $item = $order->items()->first();
        $this->operation($order, 'fulfill', ['items' => [['order_item_id' => $item->id, 'quantity' => 1]], 'carrier' => 'Manual', 'tracking_number' => 'TRACK-1', 'tracking_url' => 'https://example.com/track/1'])->assertOk()->assertJsonPath('fulfillment_status', 'partially_fulfilled');
        $this->operation($order, 'edit', ['reason' => 'Bad edit', 'items' => [['variant_id' => $shop[2]->id, 'quantity' => 0]]])->assertUnprocessable();
        $this->operation($order, 'edit', ['reason' => 'Too late', 'address' => 'Other address'])->assertUnprocessable();
        $this->operation($order, 'fulfill', ['items' => [['order_item_id' => $item->id, 'quantity' => 2]]])->assertUnprocessable();
        $this->operation($order, 'cancel', ['reason' => 'Cancel remainder'])->assertOk();
        $this->assertSame(9, $shop[3]->fresh()->quantity);
        $this->operation($order, 'cancel', ['reason' => 'Again'])->assertUnprocessable();
        $this->assertSame(9, $shop[3]->fresh()->quantity);
    }

    public function test_returns_restock_once_and_refunds_require_recorded_money(): void
    {
        $shop = $this->shop();
        $order = $this->placed($shop);
        $this->admin();
        $item = $order->items()->first();
        $this->operation($order, 'refund', ['amount' => 100, 'method' => 'cash', 'reference' => 'REF-NO-PAY', 'note' => 'Refund', 'confirmed' => true])->assertUnprocessable();
        $this->operation($order, 'payment', ['amount' => 2120, 'method' => 'bkash', 'reference' => 'PAY-1', 'note' => 'Verified merchant statement', 'confirmed' => true])->assertOk()->assertJsonPath('payment_status', 'paid');
        $this->operation($order, 'fulfill', ['items' => [['order_item_id' => $item->id, 'quantity' => 2]]])->assertOk();
        $this->operation($order, 'return', ['items' => [['order_item_id' => $item->id, 'quantity' => 1]], 'reason' => 'Wrong size'])->assertOk();
        $return = $order->returns()->first();
        $this->operation($order, 'return', ['items' => [['order_item_id' => $item->id, 'quantity' => 2]], 'reason' => 'Too many'])->assertUnprocessable();
        $this->operation($order, 'return_status', ['return_id' => $return->id, 'status' => 'approved', 'restock' => false, 'reason' => 'Approved'])->assertOk();
        $this->operation($order, 'return_status', ['return_id' => $return->id, 'status' => 'received', 'restock' => true, 'reason' => 'Received in good condition'])->assertOk()->assertJsonPath('refund_due', 1000);
        $this->assertSame(9, $shop[3]->fresh()->quantity);
        $this->operation($order, 'return_status', ['return_id' => $return->id, 'status' => 'received', 'restock' => true, 'reason' => 'Retry'])->assertUnprocessable();
        $this->operation($order, 'refund', ['amount' => 1000, 'method' => 'bkash', 'reference' => 'REF-1', 'note' => 'Money returned through merchant account', 'confirmed' => true])->assertOk()->assertJsonPath('refund_due', 0)->assertJsonPath('payment_status', 'partially_refunded');
        $this->operation($order, 'refund', ['amount' => 2000, 'method' => 'bkash', 'reference' => 'REF-2', 'note' => 'Too much', 'confirmed' => true])->assertUnprocessable();
    }

    public function test_manual_payment_submission_does_not_mark_order_paid(): void
    {
        $order = $this->placed($this->shop());
        Setting::create(['key' => 'payment_bkash', 'value' => 'Pay the merchant account']);
        $url = URL::temporarySignedRoute('order.customer.action', now()->addHour(), ['order' => $order->reference, 'action' => 'payment_submitted']);
        $this->postJson($url, ['revision' => 1, 'operation_key' => (string) Str::uuid(), 'method' => 'bkash', 'reference' => 'TX-1', 'amount' => 2120])->assertOk();
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame(0, $order->payments()->count());
        $this->assertSame(1, $order->events()->where('action', 'payment_submitted')->count());
        $this->admin();
        $this->operation($order, 'payment', ['amount' => 2120, 'method' => 'bkash', 'reference' => 'TX-1', 'note' => 'Not confirmed', 'confirmed' => false])->assertUnprocessable();
    }

    public function test_other_customer_and_unsigned_guest_cannot_manage_order(): void
    {
        $order = $this->placed($this->shop());
        $url = '/api/v1/orders/'.$order->reference.'/manage';
        $this->getJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
        $this->postJson('/api/v1/admin/orders/'.$order->id.'/actions/cancel', ['revision' => 1, 'operation_key' => (string) Str::uuid(), 'reason' => 'Unauthorized'])->assertForbidden();
    }

    public function test_signed_customer_can_edit_address_but_not_prices_or_stock(): void
    {
        $shop = $this->shop();
        $order = $this->placed($shop);
        $url = URL::temporarySignedRoute('order.customer.action', now()->addHour(), ['order' => $order->reference, 'action' => 'edit']);
        $data = ['revision' => 1, 'operation_key' => (string) Str::uuid(), 'reason' => 'Address correction', 'address' => 'New address'];
        $this->postJson($url, $data)->assertOk();
        $this->assertSame('New address', $order->fresh()->address);
        $this->postJson($url, array_replace($data, ['revision' => 2, 'operation_key' => (string) Str::uuid(), 'discount_total' => 2000]))->assertUnprocessable();
    }

    public function test_chattrn_cancel_changes_real_order_and_stock(): void
    {
        $shop = $this->shop();
        $order = $this->placed($shop);
        config(['chattrn.access_token' => 'test-token']);
        $url = '/chattrn/api/2026-10/orders/'.$order->id.'/cancel.json';
        $this->postJson($url)->assertUnauthorized();
        $this->withHeader('X-Chattrn-Access-Token', 'test-token')->postJson($url, ['reason' => 'Customer request'])->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $shop[3]->fresh()->quantity);
    }

    public function test_draft_completion_creates_only_one_real_order(): void
    {
        $shop = $this->shop();
        $this->admin();
        $payload = $this->payload($shop);
        $d = $this->postJson('/api/v1/admin/drafts', ['draft_order' => $payload + ['location_id' => $shop[0]->id, 'line_items' => $payload['items']]])->assertOk()->json('draft_order.id');
        $this->assertSame(10, $shop[3]->fresh()->quantity);
        $this->putJson('/api/v1/admin/drafts/'.$d.'/complete')->assertOk()->assertJsonPath('draft_order.status', 'completed');
        $this->putJson('/api/v1/admin/drafts/'.$d.'/complete')->assertOk();
        $this->assertSame(1, Order::count());
        $this->assertSame(8, $shop[3]->fresh()->quantity);
    }

    public function test_exchange_creates_separate_replacement_and_reserves_stock_once(): void
    {
        $shop = $this->shop();
        $order = $this->placed($shop);
        $this->admin();
        $item = $order->items()->first();
        $this->operation($order, 'fulfill', ['items' => [['order_item_id' => $item->id, 'quantity' => 2]]])->assertOk();
        $this->operation($order, 'return', ['items' => [['order_item_id' => $item->id, 'quantity' => 1]], 'reason' => 'Exchange'])->assertOk();
        $return = $order->returns()->first();
        $this->operation($order, 'return_status', ['return_id' => $return->id, 'status' => 'approved', 'restock' => false, 'reason' => 'Approved'])->assertOk();
        $this->operation($order, 'return_status', ['return_id' => $return->id, 'status' => 'received', 'restock' => true, 'reason' => 'Received'])->assertOk();
        $payload = ['return_id' => $return->id, 'reason' => 'Replacement', 'items' => [['variant_id' => $shop[2]->id, 'quantity' => 1]]];
        $this->operation($order, 'exchange', $payload)->assertOk();
        $this->assertDatabaseHas('orders', ['exchange_order_id' => $order->id, 'payment_status' => 'unpaid']);
        $this->assertSame(8, $shop[3]->fresh()->quantity);
        $this->operation($order, 'exchange', $payload)->assertUnprocessable();
        $this->assertSame(2, Order::count());
    }

    public function test_chattrn_draft_can_complete_without_a_browser_session(): void
    {
        $shop = $this->shop();
        config(['chattrn.access_token' => 'test-token']);
        $payload = $this->payload($shop);
        $this->withHeader('X-Chattrn-Access-Token', 'test-token');
        $draft = $this->postJson('/chattrn/api/2026-10/draft_orders.json', ['draft_order' => $payload + ['location_id' => $shop[0]->id, 'line_items' => $payload['items']]])->assertOk()->json('draft_order.id');
        $this->putJson('/chattrn/api/2026-10/draft_orders/'.$draft.'/complete.json')->assertOk()->assertJsonPath('draft_order.status', 'completed');
        $this->assertSame(8, $shop[3]->fresh()->quantity);
    }

    public function test_payment_reference_is_unique_and_payment_retry_is_idempotent(): void
    {
        $order = $this->placed($this->shop());
        $this->admin();
        $url = '/api/v1/admin/orders/'.$order->id.'/actions/payment';
        $payload = ['revision' => 1, 'operation_key' => (string) Str::uuid(), 'amount' => 1000, 'method' => 'cash', 'reference' => 'CASH-RECEIPT-1', 'note' => 'Receipt verified', 'confirmed' => true];
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('payment_status', 'partially_paid');
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame(1, $order->payments()->count());
        $this->operation($order, 'payment', ['amount' => 1000, 'method' => 'cash', 'reference' => 'CASH-RECEIPT-1', 'note' => 'Duplicate', 'confirmed' => true])->assertUnprocessable();
    }

    public function test_shipment_tracking_can_be_updated_and_unsafe_urls_are_rejected(): void
    {
        $order = $this->placed($this->shop());
        $this->admin();
        $this->operation($order, 'fulfill', ['items' => [['order_item_id' => $order->items()->first()->id, 'quantity' => 2]]])->assertOk();
        $shipment = $order->shipments()->first();
        $this->operation($order, 'shipment_update', ['shipment_id' => $shipment->id, 'status' => 'delivered', 'tracking_url' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->operation($order, 'shipment_update', ['shipment_id' => $shipment->id, 'status' => 'delivered', 'carrier' => 'Manual courier', 'tracking_number' => 'TRACK-001', 'tracking_url' => 'https://example.com/track'])->assertOk();
        $this->assertSame('delivered', $shipment->fresh()->status);
    }

    public function test_chattrn_can_update_shipping_details_with_token_and_revision(): void
    {
        $order = $this->placed($this->shop());
        config(['chattrn.access_token' => 'test-token']);
        $url = '/chattrn/api/2026-10/orders/'.$order->id.'.json';
        $payload = ['order' => ['revision' => 1, 'operation_key' => (string) Str::uuid(), 'shipping_address' => ['address1' => 'Updated through Chattrn', 'city' => 'Dhaka', 'country_code' => 'BD']]];
        $this->putJson($url, $payload)->assertUnauthorized();
        $this->withHeader('X-Chattrn-Access-Token', 'test-token')->putJson($url, $payload)->assertOk()->assertJsonPath('revision', 2);
        $this->assertSame('Updated through Chattrn', $order->fresh()->address);
    }
}
