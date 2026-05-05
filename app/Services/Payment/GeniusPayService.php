<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Team;

class GeniusPayService
{
    protected string $baseUrl;
    protected string $publicKey;
    protected string $secretKey;
    protected string $merchantId;

    public function __construct()
    {
        $this->baseUrl = config('services.geniuspay.base_url', 'https://pay.genius.ci/api/v1/merchant');
        $this->publicKey = config('services.geniuspay.public_key');
        $this->secretKey = config('services.geniuspay.secret_key');
        $this->merchantId = config('services.geniuspay.merchant_id');
    }

    /**
     * Crée une session de paiement pour un changement de plan
     */
    public function createPaymentSession(Team $team, string $planId, float $amount, string $currency = 'XOF')
    {
        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->publicKey,
                'X-API-Secret' => $this->secretKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/payments', [
                'amount' => $amount,
                'currency' => $currency,
                'description' => "Abonnement VPSly - Plan " . ucfirst($planId),
                'customer' => [
                    'name' => $team->owner->name ?? 'Client VPSly',
                    'email' => $team->owner->email ?? '',
                ],
                'metadata' => [
                    'team_id' => $team->id,
                    'plan_id' => $planId,
                ],
                'success_url' => config('app.frontend_url') . '/settings/billing?success=true',
                'error_url' => config('app.frontend_url') . '/settings/billing?cancel=true',
            ]);

            if ($response->successful()) {
                return $response->json()['data'];
            }

            Log::error('GeniusPay Session Creation Failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('GeniusPay Service Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Vérifie la signature HMAC-SHA256 du webhook
     * Format : signature = HMAC-SHA256(timestamp + "." + json_payload, secret)
     */
    public function validateWebhookSignature(string $payload, string $signature, string $timestamp, string $webhookSecret): bool
    {
        $data = $timestamp . '.' . $payload;
        $expectedSignature = hash_hmac('sha256', $data, $webhookSecret);

        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        // Vérifier le timestamp (protection replay attack — 5 min)
        if (abs(time() - (int)$timestamp) > 300) {
            return false;
        }

        return true;
    }
}
