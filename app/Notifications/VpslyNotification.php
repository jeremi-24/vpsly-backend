<?php

namespace App\Notifications;

use App\Channels\WhatsApp\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VpslyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $title,
        protected string $message,
        protected string $level = 'info', // success, info, warning, error
        protected string $icon = 'bell',
        protected ?string $actionUrl = null,
        protected array $channels = ['database', 'broadcast']
    ) {}

    public function via(object $notifiable): array
    {
        $actualChannels = $this->channels; // Default: database, broadcast
        
        // On récupère les préférences de l'utilisateur
        $settings = $notifiable->backupSettings()->first();
        
        if ($settings) {
            if (in_array($settings->notification_channel, ['email', 'both'])) {
                $actualChannels[] = 'mail';
            }
            if (in_array($settings->notification_channel, ['whatsapp', 'both'])) {
                $actualChannels[] = WhatsAppChannel::class;
            }
        } else if ($this->level === 'error' || $this->level === 'warning') {
            // Fallback si pas de settings mais alerte critique
            $actualChannels[] = 'mail';
        }

        return array_unique($actualChannels);
    }

    public function toWhatsApp(object $notifiable): array
    {
        return [
            'message' => "VPSly - {$this->title}\n\n{$this->message}"
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', config('app.url'));
        $url = $this->actionUrl ? $frontendUrl . $this->actionUrl : $frontendUrl;

        $mail = (new MailMessage)
            ->subject("[VPSly] {$this->title}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line(new \Illuminate\Support\HtmlString(preg_replace('/\*\*(.*?)\*\*/', '<b>$1</b>', $this->message)))
            ->line('Consultez l\'état de votre infrastructure en temps réel sur votre tableau de bord.');

        if ($this->level === 'error') {
            $mail->error();
        }

        if ($this->actionUrl) {
            $mail->action('Accéder au Dashboard', $url);
        }

        $mail->line('Merci d\'utiliser **vpsly.tech** pour la gestion de votre infrastructure.')
             ->salutation('L\'équipe VPSly');

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'level' => $this->level,
            'icon' => $this->icon,
            'action_url' => $this->actionUrl,
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): \Illuminate\Notifications\Messages\BroadcastMessage
    {
        return new \Illuminate\Notifications\Messages\BroadcastMessage([
            'id' => $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'level' => $this->level,
            'icon' => $this->icon,
            'action_url' => $this->actionUrl,
            'created_at' => now()->toIso8601String(),
        ]);
    }
}
