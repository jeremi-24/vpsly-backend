<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;

class BackupNotificationService
{
    /**
     * Envoie les notifications de statut de sauvegarde
     */
    public function sendNotification(Backup $backup)
    {
        $app = $backup->application;
        $user = $app->user;
        if (!$user) return;

        $settings = $user->backupSettings()->first();
        if (!$settings || $settings->notification_channel === 'none') return;

        // Préparation du message
        $statusEmoji = $backup->status === 'success' ? '✅' : '❌';
        $statusText = $backup->status === 'success' ? 'réussie' : 'échouée';
        $message = "{$statusEmoji} VPSly Backup: La sauvegarde de l'application *{$app->name}* est {$statusText}.\n\n" .
                   "📄 Fichier: {$backup->name}\n" .
                   "📊 Taille: " . round($backup->size / 1024 / 1024, 2) . " MB\n" .
                   "📅 Date: " . $backup->created_at->format('d/m/Y H:i');

        if ($backup->status === 'failed') {
            $message .= "\n⚠️ Erreur: {$backup->notes}";
        }

        // 1. Notification Email
        $wantsEmail = in_array($settings->notification_channel, ['email', 'both']);
        if ($wantsEmail && $settings->notification_email) {
            $this->sendEmail($settings->notification_email, $backup, $message);
        }

        // 2. Notification WhatsApp
        $wantsWhatsapp = in_array($settings->notification_channel, ['whatsapp', 'both']);
        if ($wantsWhatsapp && $settings->notification_phone) {
            $this->sendWhatsApp($settings->notification_phone, $message);
        }
    }

    /**
     * Envoi d'email via Laravel Mail
     */
    protected function sendEmail(string $email, Backup $backup, string $text)
    {
        try {
            Mail::raw($text, function ($message) use ($email, $backup) {
                $status = $backup->status === 'success' ? 'Succès' : 'ÉCHEC';
                $message->to($email)
                        ->subject("[VPSly] Sauvegarde {$status} - {$backup->application->name}");
            });
            Log::info("Notification Email envoyée à {$email}");
        } catch (\Exception $e) {
            Log::error("Échec envoi Email Backup: " . $e->getMessage());
        }
    }

    /**
     * Envoi WhatsApp via Meta Cloud API (Structure de base)
     */
    protected function sendWhatsApp(string $number, string $text)
    {
        $token = config('services.whatsapp.token');
        $phoneId = config('services.whatsapp.phone_number_id');

        if (!$token || !$phoneId) {
            Log::warning("WhatsApp non configuré (Token ou Phone ID manquant).");
            return;
        }

        try {
            // Nettoyage du numéro (doit commencer par l'indicatif sans +)
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
