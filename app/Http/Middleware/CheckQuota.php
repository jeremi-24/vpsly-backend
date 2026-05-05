<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckQuota
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $quotaType): Response
    {
        $user = $request->user();
        if (!$user) return $next($request);

        $team = $user->currentTeam;
        if (!$team) return $next($request);

        $canProceed = false;
        $message = "Limite atteinte pour votre plan actuel.";

        switch ($quotaType) {
            case 'max_apps':
                $canProceed = $team->canCreateApplication();
                $message = "Vous avez atteint la limite d'applications de votre plan " . ($team->getPlanConfig()['name'] ?? 'Starter') . ".";
                break;
            
            case 'max_servers':
                $canProceed = $team->canAddServer();
                $message = "Vous avez atteint la limite de serveurs de votre plan " . ($team->getPlanConfig()['name'] ?? 'Starter') . ".";
                break;

            case 'max_databases':
                $canProceed = $team->canCreateDatabase();
                $message = "Vous avez atteint la limite de bases de données de votre plan " . ($team->getPlanConfig()['name'] ?? 'Starter') . ".";
                break;
            
            case 'custom_domains':
                $canProceed = $team->hasFeature('custom_domains');
                $message = "Les domaines personnalisés sont réservés aux plans Solo et Pro.";
                break;

            case 'auto_backups':
                $canProceed = $team->hasFeature('auto_backups');
                $message = "Les sauvegardes automatiques sont réservées au plan Pro.";
                break;
        }

        if (!$canProceed) {
            return response()->json([
                'message' => $message,
                'quota_reached' => true,
                'plan' => $team->plan
            ], 403);
        }

        return $next($request);
    }
}
