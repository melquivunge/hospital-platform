<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Step 7b.3: "there is news in the portal" for a result, an appointment or a discharge. Subject and body say nothing
 * about which (contract: no clinical data in e-mail); the portal, behind the password, says it.
 */
class PortalNews extends Notification implements ShouldQueue
{
    // Queued: a mail server failure is retried by the queue and never stops the delivery of the other notices.
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly string $network,
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
            ->subject("Novidade no portal do paciente — {$this->network}")
            ->greeting('Olá!')
            ->line("Há uma novidade para você no portal do paciente da {$this->network}.")
            ->action('Ver no portal', $this->url)
            ->line('Por segurança, o conteúdo só aparece depois que você entra com a sua senha.');
    }
}
