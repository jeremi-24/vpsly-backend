<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Payment\GeniusPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    protected GeniusPayService $paymentService;

    public function __construct(GeniusPayService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function handle(Request $request)
    {
        Log::info('--- WEBHOOK ATTEMPT ---');
        Log::info('Headers:', $request->headers->all());
        Log::info('Payload:', $request->all());

        $signature = $request->header('X-Webhook-Signature');
        $timestamp = $request->header('X-Webhook-Timestamp');
        $event = $request->header('X-Webhook-Event');
        $webhookSecret = config('services.geniuspay.webhook_secret');

        // Validation de la signature
        if (!$this->paymentService->validateWebhookSignature(
            $request->getContent(),
            $signature,
            $timestamp,
            $webhookSecret
        )) {
            Log::warning('GeniusPay Webhook: Invalid Signature', [
                'signature' => $signature,
                'timestamp' => $timestamp
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->json()->all();
        $data = $payload['data'] ?? [];
        $metadata = $data['metadata'] ?? [];

        Log::info('GeniusPay Webhook Received', ['event' => $event, 'data' => $data]);

        switch ($event) {
            case 'payment.success':
                $this->handlePaymentSuccess($metadata, $data);
                break;
            
            case 'payment.failed':
                Log::error('Payment Failed on GeniusPay', ['data' => $data]);
                break;
        }

        return response()->json(['status' => 'success']);
    }

    protected function handlePaymentSuccess(array $metadata, array $data)
    {
        $teamId = $metadata['team_id'] ?? null;
        $planId = $metadata['plan_id'] ?? null;

        if (!$teamId || !$planId) {
            Log::error('Payment Success Webhook: Missing metadata', ['metadata' => $metadata]);
            return;
        }

        $team = Team::find($teamId);
        if ($team) {
            // Mise à jour de l'équipe
            $team->update([
                'plan' => $planId,
                'subscription_status' => 'active',
                'last_payment_at' => now(),
                'expires_at' => now()->addMonth(), // +30 jours
                'payment_reference' => $data['reference'] ?? null
            ]);

            // Enregistrement de l'historique de paiement
            \App\Models\Payment::create([
                'team_id' => $team->id,
                'plan' => $planId,
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'XOF',
                'reference' => $data['reference'],
                'status' => 'completed',
                'method' => $data['gateway'] ?? 'geniuspay',
                'paid_at' => now(),
            ]);

            Log::info("Team {$team->id} upgraded to {$planId} via GeniusPay. Expires at: " . now()->addMonth());
            
            // On pourrait ici déclencher un email de confirmation
        }
    }
}
