<?php

namespace App\Forms\Components;

use Filament\Forms\Components\Field;

class SignaturePad extends Field
{
    protected string $view = 'filament.forms.components.signature-pad';

    protected string $canvasHeight = '220px';

    public function canvasHeight(string $height): static
    {
        $this->canvasHeight = $height;

        return $this;
    }

    public function getCanvasHeight(): string
    {
        return $this->canvasHeight;
    }
}
