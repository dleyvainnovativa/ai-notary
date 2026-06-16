<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Stripe\StripeClient;

class BillingController extends Controller
{
    private StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret'));
    }

    /** Create a Checkout Session for a fixed package. */
    public function checkout(Request $request)
    {
        $request->validate(['package' => 'required|string']);

        $packages = config('tokens.packages');
        $key = $request->package;

        abort_unless(isset($packages[$key]), 422, 'Unknown package');
        $pkg = $packages[$key];

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'customer_email' => $request->user()->email,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => config('tokens.currency'),
                    'unit_amount' => $pkg['price'],
                    'product_data' => ['name' => "{$pkg['tokens']} processing tokens"],
                ],
            ]],
            // metadata travels to the webhook — this is how we know who/what to credit
            'metadata' => [
                'user_id' => $request->user()->id,
                'package' => $key,
                'tokens' => $pkg['tokens'],
            ],
            'success_url' => route('billing.success') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('billing.cancel'),
        ]);

        return response()->json(['url' => $session->url]);
    }

    public function success()
    {
        return view('billing.result', ['ok' => true]);
    }
    public function cancel()
    {
        return view('billing.result', ['ok' => false]);
    }
}
