<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Jenssegers\Agent\Agent;

class LoginSecurityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $details;

    /**
     * Create a new notification instance.
     */
    public function __construct($details)
    {
        $this->details = $details;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $agent = new Agent();
        $agent->setUserAgent($this->details['user_agent']);
        
        $browser = $agent->browser();
        $platform = $agent->platform();
        $device = $agent->device();
        $ip = $this->details['ip'];
        $time = now()->format('d/m/Y H:i:s');

        return (new MailMessage)
            ->subject('Nouvelle connexion à votre compte VPSly ')
            ->markdown('emails.security-alert', [
                'name' => $notifiable->name,
                'browser' => $browser,
                'platform' => $platform,
                'device' => $agent->isDesktop() ? 'Ordinateur' : $device,
                'ip' => $ip,
                'time' => $time,
                'url' => config('app.frontend_url') . '/settings/profile'
            ]);
    }
}
