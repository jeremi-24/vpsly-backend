<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\LocalPersistentVolume;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApplicationVolumeController extends Controller
{
    public function index(Application $application)
    {
        $this->authorize('view', $application);
        return response()->json($application->persistentVolumes);
    }

    public function store(Request $request, Application $application)
    {
        $this->authorize('update', $application);
        $request->validate([
            'mount_path' => ['required', 'string', 'regex:/^[\/a-zA-Z0-9\._-]+$/'],
            'host_path' => 'nullable|string',
        ]);

        // Génération d'un nom de volume unique pour Docker sur le VPS
        $appSlug = Str::slug($application->name);
        $random = Str::random(4);
        $volumeName = "vpsly-vol-{$appSlug}-" . Str::slug($request->mount_path) . "-{$random}";

        $volume = $application->persistentVolumes()->create([
            'name' => $volumeName,
            'mount_path' => $request->mount_path,
            'host_path' => $request->host_path, // Optionnel, pour les bind mounts
        ]);

        return response()->json($volume, 201);
    }

    public function destroy(Application $application, $id)
    {
        $this->authorize('update', $application);
        $volume = $application->persistentVolumes()->findOrFail($id);
        $volume->delete();

        return response()->json(['message' => 'Volume unlinked. Note: Physical data on VPS remains for safety.']);
    }
}
