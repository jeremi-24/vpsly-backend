<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnvironmentVariableController extends Controller
{
    public function index(Application $application)
    {
        $this->authorize('view', $application);

        return $application->environmentVariables()
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'key' => $v->key,
                'value' => '••••••••', // Jamais exposé via l'API (Zero Leak)
                'is_secret' => $v->is_secret,
                'version' => $v->version,
                'updated_at' => $v->updated_at,
            ]);
    }

    public function store(Request $request, Application $application)
    {
        $this->authorize('update', $application);

        $data = $request->validate([
            'key' => ['required', 'string', 'max:255', 'regex:/^[A-Z0-9_]+$/'],
            'value' => 'nullable|string',
            'is_secret' => 'boolean',
        ]);

        $this->upsertVariable($application, $data);
        $this->syncIfLegacy($application);

        return response()->json(['status' => 'ok']);
    }

    public function bulk(Request $request, Application $application)
    {
        $this->authorize('update', $application);

        $data = $request->validate([
            'variables' => 'required|array',
            'variables.*.key' => ['required', 'string', 'max:255', 'regex:/^[A-Z0-9_]+$/'],
            'variables.*.value' => 'nullable|string',
            'variables.*.is_secret' => 'boolean',
        ]);

        \DB::transaction(function () use ($application, $data) {
            foreach ($data['variables'] as $v) {
                $this->upsertVariable($application, $v);
            }
        });

        $this->syncIfLegacy($application);

        return response()->json(['status' => 'ok', 'count' => count($data['variables'])]);
    }


    protected function upsertVariable(Application $application, array $data)
    {
        // Upsert avec gestion des soft deletes
        $variable = EnvironmentVariable::withTrashed()
            ->where('application_id', $application->id)
            ->where('key', $data['key'])
            ->first();

        if ($variable) {
            $variable->restore();
            $variable->update([
                'value' => $data['value'] ?? '',
                'is_secret' => $data['is_secret'] ?? true,
                'version' => $variable->version + 1,
                'updated_by' => Auth::id(),
            ]);
        } else {
            EnvironmentVariable::create([
                'application_id' => $application->id,
                'key' => $data['key'],
                'value' => $data['value'] ?? '',
                'is_secret' => $data['is_secret'] ?? true,
                'version' => 1,
                'updated_by' => Auth::id(),
            ]);
        }
    }

    public function destroy(Application $application, $id)
    {
        $this->authorize('update', $application);

        $variable = EnvironmentVariable::where('application_id', $application->id)
            ->where('id', $id)
            ->firstOrFail();

        $variable->delete();

        if ($application->deployment_mode === 'legacy_existing') {
            try {
                app(\App\Services\Deployment\LegacyConfigService::class)->syncConfiguration($application);
            } catch (\Exception $e) {
                \Log::error("Legacy Sync failed after delete: " . $e->getMessage());
            }
        }

        return response()->json(['status' => 'deleted']);
    }

    protected function syncIfLegacy(Application $application)
    {
        if ($application->deployment_mode === 'legacy_existing') {
            try {
                app(\App\Services\Deployment\LegacyConfigService::class)->syncConfiguration($application);
            } catch (\Exception $e) {
                \Log::error("Legacy Sync failed: " . $e->getMessage());
            }
        }
    }


    public function reveal(Application $application, $id)
    {
        $this->authorize('update', $application);

        $variable = EnvironmentVariable::where('application_id', $application->id)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'value' => $variable->value // Cast 'encrypted' automatique
        ]);
    }
}
