<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\StripeEvent;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __construct(private TokenService $tokens) {}

    public function handle(Request $request)
    {
        // 1. Verify the signature — rejects forged calls
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                config('services.stripe.webhook_secret')
            );
        } catch (\Throwable $e) {
            return response('Invalid signature', 400);
        }

        // 2. IDEMPOTENCY GUARD — if we've seen this event id, do nothing.
        //    firstOrCreate is atomic on the primary key; a duplicate delivery
        //    finds the existing row and we bail before crediting again.
        $record = StripeEvent::firstOrCreate(
            ['id' => $event->id],
            ['type' => $event->type]
        );
        if ($record->processed_at !== null) {
            return response('Already processed', 200);
        }

        // 3. Handle the event
        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            $userId = $session->metadata->user_id ?? null;
            $tokens = (int) ($session->metadata->tokens ?? 0);

            if ($userId && $tokens > 0 && $session->payment_status === 'paid') {
                $user = User::find($userId);
                if ($user) {
                    $this->tokens->credit(
                        $user,
                        $tokens,
                        $session->payment_intent,
                        ['package' => $session->metadata->package ?? null, 'event_id' => $event->id]
                    );
                }
            }
        }

        // 4. Mark processed — only after success, so a mid-flight failure
        //    leaves processed_at null and Stripe's retry can complete it.
        $record->update(['processed_at' => now()]);

        return response('OK', 200);
    }
}
