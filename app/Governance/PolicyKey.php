<?php

namespace App\Governance;

/**
 * V1 rule catalog (PRODUCT.md, "Governança"). No default values: until the network publishes a
 * version the rule is unconfigured and the dependent feature cannot be activated.
 */
enum PolicyKey: string
{
    case AttendanceMaxAccuracy = 'attendance_max_accuracy';
    // User decision (18/10/2026): two windows, configured by the network/unit.
    case AttendanceCheckInLead = 'attendance_check_in_lead';
    case AttendanceCheckOutGrace = 'attendance_check_out_grace';

    public function label(): string
    {
        return match ($this) {
            self::AttendanceMaxAccuracy => 'Precisão máxima da localização',
            self::AttendanceCheckInLead => 'Antecedência máxima da entrada',
            self::AttendanceCheckOutGrace => 'Prazo da saída após o fim',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AttendanceMaxAccuracy => 'Maior incerteza aceita na medição do aparelho para registrar entrada ou saída.',
            self::AttendanceCheckInLead => 'Quantos minutos antes do início o profissional pode registrar a entrada (até o fim do plantão).',
            self::AttendanceCheckOutGrace => 'Até quantos minutos depois do fim o profissional pode registrar a saída.',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::AttendanceMaxAccuracy => 'm',
            self::AttendanceCheckInLead, self::AttendanceCheckOutGrace => 'min',
        };
    }

    /**
     * Type domain of the value, not a product requirement.
     *
     * @return array{int, int}
     */
    public function bounds(): array
    {
        return match ($this) {
            self::AttendanceMaxAccuracy => [1, 10000],
            self::AttendanceCheckInLead, self::AttendanceCheckOutGrace => [0, 1440],
        };
    }
}
