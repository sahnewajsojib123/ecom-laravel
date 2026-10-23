<?php

namespace App\Console\Commands;

use App\Models\MessageDelivery;
use App\Models\SavedCart;
use App\Services\CommerceMessages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class DeliverCommerceMessages extends Command
{
    protected $signature = 'commerce:deliver-messages';

    protected $description = 'Prepare opted-in abandoned cart reminders and deliver configured store emails';

    public function handle(CommerceMessages $messages): int
    {
        foreach (SavedCart::where('consent', true)->whereNull('recovered_at')->whereNull('reminded_at')->where('updated_at', '<', now()->subHours(2))->where('updated_at', '>', now()->subDays(7))->limit(100)->get() as $cart) {
            DB::transaction(function () use ($cart) {
                $cart = SavedCart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
                if ($cart->reminded_at || $cart->recovered_at) {
                    return;
                }
                $url = URL::temporarySignedRoute('cart.recover', now()->addDays(7), ['cart' => $cart->id]);
                MessageDelivery::create(['recipient' => $cart->email, 'subject' => 'Your saved shopping bag', 'body' => "You asked us to save your shopping bag. Resume here: {$url}\nPrices and availability will be checked again. This is a one-time reminder."]);
                $cart->update(['reminded_at' => now()]);
            });
        }
        $this->info('Emails sent: '.$messages->deliver());

        return self::SUCCESS;
    }
}
