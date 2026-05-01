<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;

class MigrateToTeamsCommand extends Command
{
    protected $signature = 'vpsly:migrate-to-teams';
    protected $description = 'Migrate existing users and resources to the new team-based architecture';
    public function handle()
    {
        $this->info('Starting migration to teams...');

        DB::transaction(function () {
            $users = User::all();

            foreach ($users as $user) {
                $this->info("Processing user: {$user->email}");

                // 1. Create personal team if not exists
                $team = DB::table('teams')->where('owner_id', $user->id)->first();
                
                if (!$team) {
                    $teamId = DB::table('teams')->insertGetId([
                        'name' => "Personal Space ({$user->name})",
                        'owner_id' => $user->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $this->line(" - Created team: Personal Space");
                } else {
                    $teamId = $team->id;
                }

                // 2. Link user to team in pivot
                $exists = DB::table('team_user')
                    ->where('team_id', $teamId)
                    ->where('user_id', $user->id)
                    ->exists();

                if (!$exists) {
                    DB::table('team_user')->insert([
                        'team_id' => $teamId,
                        'user_id' => $user->id,
                        'role' => 'owner',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // 3. Set current_team_id for user
                $user->update(['current_team_id' => $teamId]);

                // 4. Update resources
                $tables = [
                    'servers',
                    'applications',
                    'standalone_databases',
                    'backups',
                    'local_persistent_volumes',
                    'environment_variables',
                    'scheduled_tasks',
                    'deployments',
                    'notifications',
                ];

                foreach ($tables as $tableName) {
                    $query = DB::table($tableName)->whereNull('team_id');
                    
                    if (\Schema::hasColumn($tableName, 'user_id')) {
                        $query->where('user_id', $user->id);
                    } elseif (\Schema::hasColumn($tableName, 'server_id')) {
                        // Pour les DBs et autres liés aux serveurs
                        $query->whereIn('server_id', function($q) use ($user) {
                            $q->select('id')->from('servers')->where('user_id', $user->id);
                        });
                    } elseif (\Schema::hasColumn($tableName, 'application_id')) {
                        // Pour les ressources liées aux apps
                        $query->whereIn('application_id', function($q) use ($user) {
                            $q->select('id')->from('applications')->where('user_id', $user->id);
                        });
                    }

                    $count = $query->update(['team_id' => $teamId]);
                    
                    if ($count > 0) {
                        $this->line(" - Updated {$count} resources in {$tableName}");
                    }
                }
            }
        });

        $this->info('Migration completed successfully!');
    }
}
