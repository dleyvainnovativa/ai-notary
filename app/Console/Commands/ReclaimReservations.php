<?php

namespace App\Console\Commands;

use App\Models\TokenReservation;
use App\Services\TokenService;
use Illuminate\Console\Command;

class ReclaimReservations extends Command
{
    protected $signature = 'tokens:reclaim';
    protected $description = 'Release token reservations that expired without settling';

    public function handle(TokenService $tokens): int
    {
        $stale = TokenReservation::where('status', 'active')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($stale as $reservation) {
            $tokens->release($reservation);
            $this->info("Released stale reservation #{$reservation->id}");
        }

        $this->info("Reclaimed {$stale->count()} reservation(s).");
        return self::SUCCESS;
    }
}
