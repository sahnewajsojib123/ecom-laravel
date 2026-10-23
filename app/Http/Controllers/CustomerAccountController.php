<?php

namespace App\Http\Controllers;

use App\Models\LoginCode;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\SavedCart;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CustomerAccountController extends Controller
{
    public function requestCode(Request $request): array
    {
        $data = $request->validate(['email' => 'required|email|max:200']);
        abort_unless(config('commerce.email_enabled'), 503, 'Email sign-in is not configured yet. Use the secure order link shown after checkout.');
        $email = mb_strtolower(trim($data['email']));
        if (! User::whereRaw('LOWER(email) = ?', [$email])->where('is_admin', true)->exists()) {
            $code = (string) random_int(100000, 999999);
            LoginCode::updateOrCreate(['email' => $email], ['code_hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(10)]);
            Mail::raw("Your store sign-in code is {$code}. It expires in 10 minutes. If you did not request it, ignore this email.", fn ($mail) => $mail->to($email)->subject('Your store sign-in code'));
        }

        return ['message' => 'If this address can sign in, a code has been sent.'];
    }

    public function verify(Request $request): array
    {
        abort_unless(config('commerce.email_enabled'), 503, 'Email sign-in is disabled.');
        $data = $request->validate(['email' => 'required|email|max:200', 'code' => 'required|digits:6']);
        $user = DB::transaction(function () use ($data) {
            $email = mb_strtolower(trim($data['email']));
            $code = LoginCode::where('email', $email)->lockForUpdate()->first();
            if (! $code || $code->expires_at->isPast() || $code->attempts >= 5 || ! Hash::check($data['code'], $code->code_hash)) {
                if ($code) {
                    $code->increment('attempts');
                }

                return null;
            }
            $user = User::firstOrCreate(['email' => $email], ['name' => 'Customer', 'password' => Str::random(64)]);
            if ($user->is_admin) {
                return null;
            }
            $user->forceFill(['email_verified_at' => now()])->save();
            Order::whereNull('user_id')->whereRaw('LOWER(email) = ?', [$email])->update(['user_id' => $user->id]);
            $code->delete();

            return $user;
        });
        abort_unless($user, 422, 'Invalid or expired sign-in code.');
        Auth::login($user);
        $request->session()->regenerate();

        return ['ok' => true];
    }

    public function me(Request $request): array
    {
        abort_unless($request->user() && ! $request->user()->is_admin, 401);

        return ['user' => $request->user()->only('name', 'email'), 'orders' => Order::where('user_id', $request->user()->id)->latest()->get()->map(fn ($o) => $o->only('reference', 'status', 'total', 'payment_status', 'fulfillment_status', 'created_at') + ['url' => URL::temporarySignedRoute('order.status', now()->addDays(30), ['order' => $o->reference])])];
    }

    public function logout(Request $request): array
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ['ok' => true];
    }

    public function saveCart(Request $request): array
    {
        $data = $request->validate(['email' => 'required|email|max:200', 'outlet_id' => 'required|integer|exists:outlets,id', 'consent' => 'required|accepted', 'items' => 'required|array|min:1|max:50', 'items.*.product_id' => 'required|integer|exists:products,id', 'items.*.variant_id' => 'required|integer|distinct|exists:product_variants,id', 'items.*.quantity' => 'required|integer|min:1|max:20']);
        foreach ($data['items'] as $line) {
            abort_unless(ProductVariant::whereKey($line['variant_id'])->where('product_id', $line['product_id'])->exists(), 422, 'The variant does not belong to this product.');
        }
        $data['consent'] = true;
        SavedCart::updateOrCreate(['session_key' => hash('sha256', $request->session()->getId())], $data + ['recovered_at' => null, 'reminded_at' => null]);

        return ['ok' => true];
    }

    public function recover(Request $request, SavedCart $cart): mixed
    {
        abort_unless($request->hasValidSignature() && ! $cart->recovered_at, 403);
        $request->session()->put('saved_cart', $cart->only('items', 'outlet_id'));

        return redirect('/');
    }

    public function recoveredCart(Request $request): array
    {
        return ['cart' => $request->session()->pull('saved_cart')];
    }
}
