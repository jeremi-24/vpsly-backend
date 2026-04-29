<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BackupSetting;
use Illuminate\Http\Request;

class BackupSettingController extends Controller
{
    /**
     * Récupère les réglages de sauvegarde de l'utilisateur
     */
    public function show(Request $request)
    {
        $settings = BackupSetting::firstOrCreate(
            ['user_id' => $request->user()->id],
            [
                'frequency' => 'daily',
                'execution_time' => '02:00',
                'storage_destination' => 'local',
                'notification_channel' => 'email',
                'active' => true,
            ]
        );

        return response()->json($settings);
    }

    /**
     * Sauvegarde ou met à jour les réglages
     */
    public function store(Request $request)
    {
        $request->validate([
            'frequency' => 'required|string|in:hourly,daily,weekly,manual',
            'execution_time' => 'required|string',
            'storage_destination' => 'required|string|in:local,google_drive,s3',
            'notification_channel' => 'required|string|in:email,whatsapp,both,none',
            'notification_phone' => 'nullable|string',
            'notification_email' => 'nullable|email',
            'active' => 'required|boolean',
        ]);

        $settings = BackupSetting::updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->all()
        );

        return response()->json([
            'message' => 'Paramètres de sauvegarde mis à jour.',
            'settings' => $settings
        ]);
    }
}
