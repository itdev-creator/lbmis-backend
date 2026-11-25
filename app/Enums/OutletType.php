<?php

namespace App\Enums;

enum OutletType: string
{
    case Own = 'own';
    case JointVenture = 'jv';
    case Franchise = 'franchise';
    case Alacart = 'alacart';

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Own company',
            self::JointVenture => 'Joint venture',
            self::Franchise => 'Franchise',
            self::Alacart => 'Alacart',
        };
    }

    /**
     * Corporate outlets follow the corporate SOP; franchise outlets the franchise SOP.
     */
    public function isFranchise(): bool
    {
        return in_array($this, [self::Franchise, self::Alacart], true);
    }

    public function category(): string
    {
        return $this->isFranchise() ? 'Franchise' : 'Corporate';
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
