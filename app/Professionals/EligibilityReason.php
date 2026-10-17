<?php

namespace App\Professionals;

/**
 * Why a professional cannot take a shift. Shown to coordinators and recorded with decisions.
 */
enum EligibilityReason: string
{
    case InvalidPeriod = 'invalid_period';
    case OtherNetwork = 'other_network';
    case UnitIncomplete = 'unit_incomplete';
    case DifferentUf = 'different_uf';
    case NoQualification = 'no_qualification';
    case QualificationPending = 'qualification_pending';
    case QualificationSuspended = 'qualification_suspended';
    case QualificationRevoked = 'qualification_revoked';
    case OutsideValidity = 'outside_validity';
    case SectorNotAuthorized = 'sector_not_authorized';

    public function message(): string
    {
        return match ($this) {
            self::InvalidPeriod => 'O início do plantão precisa ser anterior ao fim.',
            self::OtherNetwork => 'O profissional pertence a outra instituição.',
            self::UnitIncomplete => 'A unidade não tem UF ou fuso horário cadastrados.',
            self::DifferentUf => 'O plantão é em outra UF.',
            self::NoQualification => 'Sem habilitação para esta função nesta unidade.',
            self::QualificationPending => 'A habilitação ainda não foi aprovada.',
            self::QualificationSuspended => 'A habilitação está suspensa.',
            self::QualificationRevoked => 'A habilitação foi revogada.',
            self::OutsideValidity => 'A habilitação não cobre todo o período do plantão.',
            self::SectorNotAuthorized => 'O setor não está autorizado na habilitação.',
        };
    }
}
