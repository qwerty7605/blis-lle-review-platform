<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes sign-ups whose verification link has expired unopened.
 *
 * Registration already lets a new sign-up replace one of these, so this is
 * housekeeping rather than what frees the address: without it every mistyped
 * or abandoned registration would sit in the Users list for good.
 */
class PruneUnverifiedRegistrationsCommand extends Command
{
    protected $signature = 'registrations:prune-unverified';

    protected $description = 'Delete self-registered accounts that were never verified before their link expired';

    public function handle(): int
    {
        $expired = User::query()
            ->unclaimedRegistrations()
            ->where('created_at', '<', now()->subMinutes((int) config('auth.verification.expire', 1440)))
            ->get(['id', 'email']);

        DB::transaction(function () use ($expired): void {
            DB::table('password_reset_tokens')->whereIn('email', $expired->pluck('email'))->delete();
            User::query()->whereIn('id', $expired->pluck('id'))->delete();
        });

        $this->info(sprintf('Removed %d unverified sign-up(s).', $expired->count()));

        return self::SUCCESS;
    }
}
