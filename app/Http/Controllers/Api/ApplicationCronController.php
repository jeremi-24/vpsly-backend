<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ScheduledTask;
use App\Services\Deployment\CronService;
use Illuminate\Http\Request;

class ApplicationCronController extends Controller
{
    public function __construct(protected CronService $cronService) {}

    public function index(Application $application)
    {
        return response()->json([
            'build_pack' => $application->build_pack,
            'has_laravel_scheduler' => $application->has_laravel_scheduler,
            'last_cron_synced_at' => $application->last_cron_synced_at,
            'last_cron_sync_error' => $application->last_cron_sync_error,
            'tasks' => $application->scheduledTasks()->get(),
        ]);
    }

    public function toggleLaravel(Application $application, Request $request)
    {
        $application->update([
            'has_laravel_scheduler' => $request->boolean('active'),
        ]);

        return $this->sync($application);
    }

    public function store(Application $application, Request $request)
    {
        $validated = $request->validate([
            'command' => 'required|string',
            'frequency' => 'required|string',
            'description' => 'nullable|string',
        ]);

        $task = $application->scheduledTasks()->create($validated);

        return $this->sync($application);
    }

    public function destroy(Application $application, $id)
    {
        $application->scheduledTasks()->where('id', $id)->delete();

        return $this->sync($application);
    }

    public function sync(Application $application)
    {
        try {
            $this->cronService->sync($application);
            return $this->index($application);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Sync failed: ' . $e->getMessage(),
                'last_cron_sync_error' => $application->fresh()->last_cron_sync_error
            ], 500);
        }
    }
}
