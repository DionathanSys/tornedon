<?php

namespace App\Filament\Components;

use App\Rules\ValidNcm;
use App\Services\NcmService;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class NcmCodeInput
{
    public static function make(string $name = 'ncm_code'): TextInput
    {
        return TextInput::make($name)
            ->label('Código NCM')
            ->columnSpan(['md' => 1, 'lg' => 3])
            ->mask('9999.99.99')
            ->placeholder('0000.00.00')
            ->maxLength(10)
            ->rules([new ValidNcm()])
            ->live(onBlur: true)
            ->afterStateUpdated(function ($state, Component $component) {
                if (empty($state)) {
                    $component->belowContent(null);
                    return;
                }

                $ncmService = app(NcmService::class);
                $info = $ncmService->getValidityInfo($state);

                if (!$info) {
                    $component->belowContent(Schema::start([
                        Icon::make(Heroicon::XCircle)
                            ->color('danger'),
                        Text::make('Código NCM não encontrado na tabela vigente.')
                            ->color('danger'),
                    ]));
                    return;
                }

                $html = '<strong>' . e($info['description']) . '</strong><br>';
                $html .= '<small>Vigência: ' . $info['start_date'];
                $html .= $info['end_date'] ? ' até ' . $info['end_date'] : ' (sem data fim)';
                $html .= '</small>';

                if ($info['is_expired']) {
                    $html .= '<br><span style="color: #f59e0b;">Este código NCM está fora da vigência!</span>';
                }

                if ($info['is_not_yet_valid']) {
                    $html .= '<br><span style="color: #f59e0b;">Este código NCM ainda não entrou em vigência.</span>';
                }

                $component->belowContent(new HtmlString($html));
            })
            ->helperText('O código será validado automaticamente na tabela NCM vigente.');
    }
}
