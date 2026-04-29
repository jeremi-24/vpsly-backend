<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BackupSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleDriveController extends Controller
{
    /**
     * Redirige l'utilisateur pour connecter son Drive.
     */
    public function redirect(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        $state = \Illuminate\Support\Str::random(40);
        
        // Stocker l'ID utilisateur dans le cache avec la clé state (valide 10 min)
        \Illuminate\Support\Facades\Cache::put('google_drive_auth_' . $state, $user->id, 600);

        // Forcer l'URL de redirection dans la config de session pour ce driver
        config(['services.google.redirect' => config('services.google.drive_redirect')]);

        $url = Socialite::driver('google')
            ->scopes(['https://www.googleapis.com/auth/drive.file'])
            ->stateless()
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent select_account',
                'state' => $state
            ])
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    /**
     * Gère le retour de Google après l'autorisation du Drive.
     */
    public function callback(Request $request)
    {
        try {
            $state = $request->state;
            
            if (!$state || !\Illuminate\Support\Facades\Cache::has('google_drive_auth_' . $state)) {
                throw new \Exception("Session OAuth expirée ou invalide.");
            }

            $userId = \Illuminate\Support\Facades\Cache::pull('google_drive_auth_' . $state);
            $user = \App\Models\User::findOrFail($userId);

            // Forcer l'URL de redirection dans la config pour le callback
            config(['services.google.redirect' => config('services.google.drive_redirect')]);

            $socialUser = Socialite::driver('google')
                ->stateless()
                ->user();

            // Sauvegarder les tokens dans les réglages de backup
            $settings = BackupSetting::firstOrCreate([
                'user_id' => $user->id
            ]);

            $settings->update([
                'storage_destination' => 'google_drive',
                'storage_credentials' => [
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                    'expires_in' => $socialUser->expiresIn,
                    'email' => $socialUser->email,
                    'connected_at' => now(),
                ]
            ]);

            // Rediriger vers le dashboard frontend (onglet intégrations)
            $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');
            return redirect($frontendUrl . '/settings/integrations?success=google_drive');

        } catch (\Exception $e) {
            $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');
            return redirect($frontendUrl . '/settings/integrations?error=' . urlencode($e->getMessage()));
        }
    }

    /**
     * Déconnecte le Drive.
     */
    public function disconnect()
    {
        $settings = BackupSetting::where('user_id', Auth::id())->first();
        if ($settings) {
            $settings->update([
                'storage_destination' => 'local',
                'storage_credentials' => null
            ]);
        }

        return response()->json(['message' => 'Google Drive déconnecté.']);
    }
}
