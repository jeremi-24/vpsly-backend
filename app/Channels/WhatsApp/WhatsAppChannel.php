<?php

namespace App\Channels\WhatsApp;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppChannel
{
    /**
     * Send the given notification.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $data = $notification->toWhatsApp($notifiable);
        
        $number = $data['to'] ?? $notifiable->phone;
        $text = $data['message'];

        if (!$number) {
            Log::warning("WhatsApp: No phone number for notifiable.");
            return;
        }

        $token = config('services.whatsapp.token');
        $phoneId = config('services.whatsapp.phone_number_id');

        if (!$token || !$phoneId) {
            Log::warning("WhatsApp non configuré (Token ou Phone ID manquant).");
            return;
        }

        try {
            $cleanNumber = preg_replace('/[^0-9]/', '', $number);

            $response = Http::withToken($token)
                ->post("https://graph.facebook.com/v20.0/{$phoneId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $cleanNumber,
                    'type' => 'text',
                    'text' => ['body' => $text]
                ]);

            if ($response->failed()) {
                Log::error("Erreur API WhatsApp: " . $response->body());
            } else {
                Log::info("Notification WhatsApp envoyée à {$number}");
            }
        } catch (\Exception $e) {
            Log::error("Échec envoi WhatsApp: " . $e->getMessage());
        }
    }
}
