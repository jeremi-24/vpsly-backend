<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $user;

    /**
     * Create a new notification instance.
     */
    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Bienvenue sur VPSly!')
            ->greeting('Bonjour ' . $this->user->name . ' !')
            ->line('Nous sommes ravis de vous compter parmi nous. Votre compte VPSly a été créé avec succès.')
            ->line('Vous pouvez désormais connecter vos serveurs et déployer vos premières applications en quelques minutes.')
            ->action('Accéder au Dashboard', config('app.frontend_url') . '/dashboard')
            ->line('Si vous avez des questions, n\'hésitez pas à répondre à cet email.')
            ->salutation('L\'équipe VPSly');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Bienvenue sur VPSly !',
            'message' => 'Votre compte a été créé avec succès. Bienvenue à bord !',
            'level' => 'success',
            'icon' => 'user-plus'
        ];
    }
}
