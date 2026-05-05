<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        return $request->user()->teams()->with('owner:id,name')->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = $request->user();

        // 1. Création de l'équipe
        $team = Team::create([
            'name' => $validated['name'],
            'owner_id' => $user->id,
        ]);

        // 2. Attacher l'utilisateur avec le rôle owner
        $user->teams()->attach($team->id, ['role' => 'owner']);

        // 3. Switcher vers cette équipe
        $user->update(['current_team_id' => $team->id]);

        return response()->json([
            'message' => 'Espace de travail créé avec succès',
            'team' => $team
        ], 201);
    }

    public function switch(Request $request, Team $team)
    {
        if (!$request->user()->canAccessTeam($team)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $user = $request->user();
        $user->current_team_id = $team->id;
        $user->save();

        \Illuminate\Support\Facades\Log::info("User {$user->id} switched to team {$team->id}");

        return response()->json([
            'message' => 'Équipe changée avec succès',
            'team' => $team
        ]);
    }

    public function createInvitation(Request $request)
    {
        $request->validate([
            'team_id' => 'required|exists:teams,id',
            'role' => 'nullable|string|in:admin,member',
        ]);

        $team = Team::findOrFail($request->team_id);

        \Illuminate\Support\Facades\Log::info("Invitation Debug", [
            'team_owner_id' => $team->owner_id,
            'auth_user_id' => $request->user()->id,
            'match' => $team->owner_id == $request->user()->id
        ]);

        if ($team->owner_id != $request->user()->id) {
            return response()->json(['message' => 'Seul le propriétaire peut inviter'], 403);
        }

        // Vérification du quota de membres
        $limit = data_get($team->getPlanConfig(), 'max_team_members', 1);
        $currentMembers = $team->members()->count();
        
        if ($limit !== -1 && $currentMembers >= $limit) {
            $planName = $team->getPlanConfig()['name'] ?? 'actuel';
            return response()->json([
                'message' => "Vous avez atteint la limite de membres de votre plan {$planName} (Max: {$limit}).",
                'quota_reached' => true
            ], 403);
        }

        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'token' => Str::random(40),
            'role' => $request->role ?? 'member',
            'expires_at' => now()->addDays(7),
        ]);

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');

        return response()->json([
            'invitation_url' => $frontendUrl . '/invitations/' . $invitation->token
        ]);
    }

    public function acceptInvitation(Request $request, $token)
    {
        $invitation = TeamInvitation::where('token', $token)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->firstOrFail();

        $user = $request->user();

        // Join the team
        $user->teams()->syncWithoutDetaching([$invitation->team_id => ['role' => $invitation->role]]);
        
        // Set as current team
        $user->update(['current_team_id' => $invitation->team_id]);

        // Delete invitation (one-time use)
        $invitation->delete();

        return response()->json([
            'message' => "Vous avez rejoint l'équipe : " . $invitation->team->name,
            'team' => $invitation->team
        ]);
    }
    public function members(Request $request, Team $team)
    {
        if (!$request->user()->canAccessTeam($team)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return $team->members()->select('users.id', 'users.name', 'users.email', 'team_user.role', 'team_user.created_at')->get();
    }

    public function removeMember(Request $request, Team $team, \App\Models\User $user)
    {
        // 1. Seul le owner peut retirer des membres
        if ($team->owner_id !== $request->user()->id) {
            return response()->json(['message' => 'Action non autorisée'], 403);
        }

        // 2. On ne peut pas se retirer soi-même (utiliser un autre endpoint si besoin, mais ici c'est de la gestion)
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Vous ne pouvez pas vous retirer vous-même de votre propre équipe'], 422);
        }

        $team->members()->detach($user->id);

        return response()->json(['message' => 'Membre retiré avec succès']);
    }

    public function leave(Request $request, Team $team)
    {
        $user = $request->user();

        // Le propriétaire ne peut pas quitter son propre espace
        if ($team->owner_id === $user->id) {
            return response()->json(['message' => 'Le propriétaire ne peut pas quitter son propre espace. Supprimez l\'espace ou transférez la propriété.'], 422);
        }

        if (!$user->canAccessTeam($team)) {
            return response()->json(['message' => 'Vous n\'êtes pas membre de cet espace'], 403);
        }

        $team->members()->detach($user->id);

        // Basculer sur une autre équipe si c'était l'équipe courante
        if ($user->current_team_id === $team->id) {
            $nextTeam = $user->teams()->first();
            $user->update(['current_team_id' => $nextTeam?->id]);
        }

        return response()->json(['message' => 'Vous avez quitté l\'équipe']);
    }
}
