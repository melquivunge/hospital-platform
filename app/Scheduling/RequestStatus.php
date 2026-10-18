<?php

namespace App\Scheduling;

/**
 * pending_recipient → pending_coordinator → completed; refused, cancelled and expired are terminal.
 * Same list as shift_requests_status_check.
 */
enum RequestStatus: string
{
    case PendingRecipient = 'pending_recipient';
    case PendingCoordinator = 'pending_coordinator';
    case Completed = 'completed';
    case Refused = 'refused';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * @return list<self>
     */
    public static function pending(): array
    {
        return [self::PendingRecipient, self::PendingCoordinator];
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingRecipient => 'Aguardando o colega',
            self::PendingCoordinator => 'Aguardando a coordenação',
            self::Completed => 'Concluída',
            self::Refused => 'Recusada',
            self::Cancelled => 'Cancelada',
            self::Expired => 'Expirada',
        };
    }
}
