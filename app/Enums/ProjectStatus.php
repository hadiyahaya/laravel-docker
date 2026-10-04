<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Planning = 'planning';
    case Active = 'active';
    case Completed = 'completed';

    /**
     * Text to show in the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Planning => __('Planning'),
            self::Active => __('Active'),
            self::Completed => __('Completed'),
        };
    }
}
