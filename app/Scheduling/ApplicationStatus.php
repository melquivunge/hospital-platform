<?php

namespace App\Scheduling;

/**
 * active → withdrawn | selected | not_selected; selected → replaced when the shift is later handed over
 * through an announcement (step 6c). Same list as shift_applications_status_check.
 */
enum ApplicationStatus: string
{
    case Active = 'active';
    case Withdrawn = 'withdrawn';
    case Selected = 'selected';
    case NotSelected = 'not_selected';
    case Replaced = 'replaced';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Candidatura ativa',
            self::Withdrawn => 'Retirada',
            self::Selected => 'Selecionada',
            self::NotSelected => 'Não selecionada',
            self::Replaced => 'Selecionada, depois substituída',
        };
    }
}
