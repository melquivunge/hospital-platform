<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Step 7a.1a: the single-use link to the patient portal, for an invitation or a forgotten password.
 * Carries no clinical data and no name: only the network and the link.
 */
class PortalAccessLink extends Notification
{
    public function __construct(
        public readonly string $url,
        public readonly string $network,
        public readonly bool $invitation,
    ) {}

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
            ->subject($this->invitation ? "Seu acesso ao portal do paciente — {$this->network}" : "Nova senha do portal do paciente — {$this->network}")
            ->greeting('Olá!')
            ->line($this->invitation
                ? "A recepção da {$this->network} liberou o seu acesso ao portal do paciente."
                : 'Recebemos um pedido para criar uma nova senha do portal do paciente.')
            ->action('Criar minha senha', $this->url)
            ->line('O link vale por 72 horas e só pode ser usado uma vez.')
            ->line('Se você não esperava este e-mail, ignore-o: nada muda na sua conta.');
    }
}
