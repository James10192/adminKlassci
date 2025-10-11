<?php

namespace App\Filament\Widgets;

use Filament\Widgets\AccountWidget;

class CustomAccountWidget extends AccountWidget
{
    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = -1; // Afficher en premier (avant les autres widgets)
}
