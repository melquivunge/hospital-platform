<?php

namespace App\Scheduling;

/**
 * active → withdrawn | selected | not_selected. Same list as shift_applications_status_check.
 */
enum ApplicationStatus: string
{
    case Active = 'active';
    case Withdrawn = 'withdrawn';
    case Selected = 'selected';
    case NotSelected = 'not_selected';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Candidatura ativa',
            self::Withdrawn => 'Retirada',
            self::Selected => 'Selecionada',
            self::NotSelected => 'Não selecionada',
        };
    }
}
