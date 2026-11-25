<?php

namespace App\Enums;

enum OutletStage: string
{
    case Pipeline = 'pipeline';
    case Opening = 'opening';
    case Operating = 'operating';
    case Renewal = 'renewal';
    case ExitReview = 'exit_review';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Pipeline => 'Pipeline',
            self::Opening => 'Opening',
            self::Operating => 'Operating',
            self::Renewal => 'Renewal',
            self::ExitReview => 'Exit review',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pipeline => 'zinc',
            self::Opening => 'blue',
            self::Operating => 'green',
            self::Renewal => 'amber',
            self::ExitReview => 'orange',
            self::Closed => 'red',
        };
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
