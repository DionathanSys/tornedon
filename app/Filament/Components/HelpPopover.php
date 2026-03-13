<?php

namespace App\Filament\Components;

use Filament\Schemas\Components\View;

class HelpPopover
{
    public static function make(string $title, string $content): View
    {
        return View::make('filament.components.help-popover')
            ->viewData([
                'title' => $title,
                'content' => $content,
            ]);
    }
}
