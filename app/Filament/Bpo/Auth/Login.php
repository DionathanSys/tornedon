<?php

namespace App\Filament\Bpo\Auth;

class Login extends \Filament\Auth\Pages\Login
{
    protected static string $layout = 'components.bpo.auth-layout';

    public function getHeading(): string
    {
        return 'Entre na sua área de trabalho';
    }
}
