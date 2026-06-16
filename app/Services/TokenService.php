<?php

namespace App\Services;

use App\Models\User;
use App\Models\TokenTransaction;
use App\Models\TokenReservation;
use Illuminate\Support\Facades\DB;

class TokenService
{
    /** Available balance = ledger sum (reservations already count as negative). */
    public function balance(User $user): int
    {
        return (int) $user->tokenTransactions()->sum('tokens');
    }

    /** Credit purchased tokens. Idempotency is enforced by the caller (webhook). */
    public function credit(User $user, int $tokens, string $stripePaymentId, array $meta = []): TokenTransaction
    {
        return $user->tokenTransactions()->create([
            'type' => 'purchase',
            'tokens' => $tokens,
            'stripe_payment_id' => $stripePaymentId,
            'metadata_json' => $meta,
        ]);
    }

    /**
     * Reserve 1 token for a processing run. Returns the reservation or throws if no balance.
     * Wrapped in a transaction with row locking to prevent double-spend under concurrency.
     */
    public function reserve(User $user, array $context = []): TokenReservation
    {
        return DB::transaction(function () use ($user, $context) {
            // Lock the user's ledger rows for this check
            $balance = (int) $user->tokenTransactions()->lockForUpdate()->sum('tokens');

            if ($balance < 1) {
                throw new \RuntimeException('Insufficient tokens', 402);
            }

            $txn = $user->tokenTransactions()->create([
                'type' => 'reserved',
                'tokens' => -1,
                'metadata_json' => $context,
            ]);

            return TokenReservation::create([
                'user_id' => $user->id,
                'transaction_id' => $txn->id,
                'status' => 'active',
                'expires_at' => now()->addMinutes(config('tokens.reservation_ttl_minutes')),
                'context_json' => $context,
            ]);
        });
    }

    /** Processing succeeded → convert the reservation into a permanent 'use'. */
    public function consume(TokenReservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            if ($reservation->status !== 'active') return; // already settled
            // The −1 'reserved' txn stays as the permanent debit; just relabel intent.
            $reservation->transaction()->update(['type' => 'use']);
            $reservation->update(['status' => 'consumed']);
        });
    }

    /** Processing failed → give the token back. */
    public function release(TokenReservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            if ($reservation->status !== 'active') return;
            // Add a +1 'released' entry to reverse the −1 reservation.
            $reservation->user->tokenTransactions()->create([
                'type' => 'released',
                'tokens' => 1,
                'metadata_json' => ['reverses_transaction_id' => $reservation->transaction_id],
            ]);
            $reservation->update(['status' => 'released']);
        });
    }

    /** Manual refund (admin, Phase 8). */
    public function refund(User $user, int $tokens, string $reason): TokenTransaction
    {
        return $user->tokenTransactions()->create([
            'type' => 'refund',
            'tokens' => $tokens,
            'metadata_json' => ['reason' => $reason],
        ]);
    }
}
