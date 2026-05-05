<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\GeniusPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    protected GeniusPayService $paymentService;

    public function __construct(GeniusPayService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Crée une session de paiement pour un utilisateur connecté
     */
    public function createSession(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|string|in:solo,pro'
        ]);

        $user = Auth::user();
        $team = $user->currentTeam;

        if (!$team) {
            return response()->json(['error' => 'No team selected'], 400);
        }

        // Définition des montants (XOF)
        $prices = [
            'solo' => 3000, // 6$ (Taux 500 FCFA/$)
            'pro' => 5800,  // Prix fixe demandé
        ];

        $planId = $request->plan_id;
        $amount = $prices[$planId];

        try {
            $session = $this->paymentService->createPaymentSession($team, $planId, $amount);
            
            if (!$session || !isset($session['checkout_url'])) {
                return response()->json(['error' => 'Failed to generate checkout URL from GeniusPay'], 500);
            }

            return response()->json([
                'checkout_url' => $session['checkout_url'],
                'message' => 'Lien de paiement généré avec succès.'
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
