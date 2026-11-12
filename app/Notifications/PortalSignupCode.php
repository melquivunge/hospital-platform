<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Step 7a.1b: the code that proves the self sign-up comes from whoever reads the e-mail on file.
 * No name and no clinical data. Not queued: the payload would keep the live code in plain text.
 */
class PortalSignupCode extends Notification
{
    public function __construct(public readonly string $code, public readonly string $network) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Seu código de primeiro acesso — {$this->network}")
            ->greeting('Olá!')
            ->line("Use este código para criar seu acesso ao portal do paciente da {$this->network}:")
            ->line("**{$this->code}**")
            ->line('Ele vale por 10 minutos. Ninguém da equipe pede este código.')
            ->line('Se não foi você, ignore este e-mail: nada muda na sua conta.');
    }
}
