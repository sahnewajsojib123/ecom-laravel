<?php

namespace App\Services;

use App\Models\MessageDelivery;
use App\Models\Order;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class CommerceMessages
{
    public function order(Order $order, string $event): void
    {
        if (! $order->email) {
            return;
        }
        $link = URL::temporarySignedRoute('order.status', now()->addDays(30), ['order' => $order->reference]);
        MessageDelivery::create(['order_id' => $order->id, 'recipient' => $order->email, 'subject' => config('app.name').' — '.$event,
            'body' => "Order {$order->reference}\n{$event}\nOrder total: BDT {$order->total}\nPayment status: {$order->payment_status}\nManage your order: {$link}\n"]);
    }

    public function deliver(int $limit = 20): int
    {
        if (! config('commerce.email_enabled')) {
            return 0;
        }
        $sent = 0;
        foreach (MessageDelivery::whereIn('status', ['pending', 'failed'])->where('attempts', '<', 5)->orderBy('id')->limit($limit)->get() as $message) {
            if (! MessageDelivery::whereKey($message->id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'sending'])) {
                continue;
            }
            try {
                Mail::raw($message->body, fn ($mail) => $mail->to($message->recipient)->subject($message->subject));
                $message->update(['status' => 'sent', 'sent_at' => now(), 'attempts' => $message->attempts + 1, 'last_error' => null]);
                $sent++;
            } catch (\Throwable $error) {
                $message->update(['status' => 'failed', 'attempts' => $message->attempts + 1, 'last_error' => 'Delivery failed. Check mail transport configuration.']);
                report($error);
            }
        }

        return $sent;
    }
}
