<?php

namespace App\Governance;

/**
 * V1 rule catalog (PRODUCT.md, "Governança"). No default values: until the network publishes a
 * version the rule is unconfigured and the dependent feature cannot be activated.
 */
enum PolicyKey: string
{
    case AttendanceMaxAccuracy = 'attendance_max_accuracy';

    public function label(): string
    {
        return match ($this) {
            self::AttendanceMaxAccuracy => 'Precisão máxima da localização',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AttendanceMaxAccuracy => 'Maior incerteza aceita na medição do aparelho para registrar entrada ou saída.',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::AttendanceMaxAccuracy => 'm',
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
        };
    }
}
