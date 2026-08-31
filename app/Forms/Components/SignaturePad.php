<?php

namespace App\Forms\Components;

use Filament\Forms\Components\Field;

class SignaturePad extends Field
{
    protected string $view = 'filament.forms.components.signature-pad';

    protected string $canvasHeight = '220px';

    protected bool $minimal = false;

    public function canvasHeight(string $height): static
    {
        $this->canvasHeight = $height;

        return $this;
    }

    public function getCanvasHeight(): string
    {
        return $this->canvasHeight;
    }

    public function minimal(bool $minimal = true): static
    {
        $this->minimal = $minimal;

        return $this;
    }

    public function isMinimal(): bool
    {
        return $this->minimal;
    }
}
