<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\Notifications\LoginSecurityNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Inscription d'un nouvel utilisateur.
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Création de l'équipe personnelle par défaut
        $team = \App\Models\Team::create([
            'name' => 'Mon Espace',
            'owner_id' => $user->id,
        ]);

        // Attachement et définition comme équipe courante
        $user->teams()->attach($team->id, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        // Envoi du mail de bienvenue
        $user->notify(new WelcomeNotification($user));

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user->load('currentTeam'),
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Connexion d'un utilisateur existant.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Les identifiants fournis sont incorrects.'],
            ]);
        }

        // Alerte de sécurité pour la connexion (uniquement si l'IP change)
        $currentIp = $request->ip();
        if ($user->last_login_ip !== $currentIp) {
            $user->notify(new LoginSecurityNotification([
                'ip' => $currentIp,
                'user_agent' => $request->userAgent(),
            ]));

            $user->update(['last_login_ip' => $currentIp]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user->load('currentTeam'),
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }
}
