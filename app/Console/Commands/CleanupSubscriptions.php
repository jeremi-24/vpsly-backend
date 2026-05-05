<?php

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vpsly:cleanup-subscriptions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Downgrade expired subscriptions to starter plan';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for expired subscriptions...');

        $expiredTeams = Team::where('plan', '!=', 'starter')
            ->where('expires_at', '<', now()->subMinute())
            ->get();

        if ($expiredTeams->isEmpty()) {
            $this->info('No expired subscriptions found.');
            return;
        }

        foreach ($expiredTeams as $team) {
            $this->warn("Downgrading team #{$team->id} ({$team->name}) - Plan {$team->plan} expired at {$team->expires_at}");
            
            $team->update([
                'plan' => 'starter',
                'subscription_status' => 'expired',
                // On laisse l'expires_at pour l'historique
            ]);

            Log::info("Team #{$team->id} automatically downgraded to starter due to expiration.");
            
            // On pourrait ici envoyer une notification mail
        }

        $this->info('Cleanup completed.');
    }
}
