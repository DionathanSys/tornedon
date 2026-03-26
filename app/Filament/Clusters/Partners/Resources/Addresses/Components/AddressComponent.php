<?php

namespace App\Filament\Clusters\Partners\Resources\Addresses\Components;

use App\Notification\NotifyService as notify;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Http;

final class AddressComponent
{
    public static function make(): array
    {
        return [
            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('postal_code')
                        ->label('CEP')
                        ->required()
                        ->mask('99999-999')
                        ->maxLength(9)
                        ->suffixAction(self::fetchCepAction())
                        ->columnSpan(1),

                    TextInput::make('street')
                        ->label('Logradouro/Rua')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),
                ]),

            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('number')
                        ->label('Número')
                        ->maxLength(20)
                        ->columnSpan(1),

                    TextInput::make('complement')
                        ->label('Complemento')
                        ->maxLength(255)
                        ->columnSpan(2),
                ]),

            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('neighborhood')
                        ->label('Bairro')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(1),

                    TextInput::make('city')
                        ->label('Cidade')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(1),

                    TextInput::make('city_code')
                        ->label('Código IBGE')
                        ->maxLength(20)
                        ->columnSpan(1),
                ]),

            Grid::make(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('state')
                        ->label('Estado')
                        ->required()
                        ->options([
                            'AC' => 'Acre',
                            'AL' => 'Alagoas',
                            'AP' => 'Amapá',
                            'AM' => 'Amazonas',
                            'BA' => 'Bahia',
                            'CE' => 'Ceará',
                            'DF' => 'Distrito Federal',
                            'ES' => 'Espírito Santo',
                            'GO' => 'Goiás',
                            'MA' => 'Maranhão',
                            'MT' => 'Mato Grosso',
                            'MS' => 'Mato Grosso do Sul',
                            'MG' => 'Minas Gerais',
                            'PA' => 'Pará',
                            'PB' => 'Paraíba',
                            'PR' => 'Paraná',
                            'PE' => 'Pernambuco',
                            'PI' => 'Piauí',
                            'RJ' => 'Rio de Janeiro',
                            'RN' => 'Rio Grande do Norte',
                            'RS' => 'Rio Grande do Sul',
                            'RO' => 'Rondônia',
                            'RR' => 'Roraima',
                            'SC' => 'Santa Catarina',
                            'SP' => 'São Paulo',
                            'SE' => 'Sergipe',
                            'TO' => 'Tocantins',
                        ])
                        ->searchable()
                        ->columnSpan(1),

                    TextInput::make('country')
                        ->label('País')
                        ->default('Brasil')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(1),
                ]),
        ];
    }

    private static function fetchCepAction(): Action
    {
        return Action::make('search-cep')
            ->label('Buscar CEP')
            ->iconButton()
            ->icon(Heroicon::MagnifyingGlassCircle)
            ->action(function (Get $get, Set $set): void {
                $postalCode = preg_replace('/\D/', '', (string) ($get('postal_code') ?? ''));

                if (strlen($postalCode) !== 8) {
                    notify::warning(
                        title: 'CEP inválido',
                        message: 'Informe um CEP com 8 dígitos para realizar a busca.',
                    );
                    return;
                }

                $response = Http::acceptJson()
                    ->timeout(10)
                    ->get("https://viacep.com.br/ws/{$postalCode}/json/");

                if ($response->failed()) {
                    notify::error(
                        title: 'Erro ao buscar CEP',
                        message: 'Não foi possível consultar o CEP no ViaCEP.',
                    );
                    return;
                }

                $data = $response->json();

                if (! is_array($data) || ($data['erro'] ?? false)) {
                    notify::warning(
                        title: 'CEP não encontrado',
                        message: 'Nenhum endereço foi encontrado para o CEP informado.',
                    );
                    return;
                }

                $set('postal_code', self::formatPostalCode($data['cep'] ?? $postalCode));
                $set('street', $data['logradouro'] ?? null);
                $set('complement', $data['complemento'] ?? null);
                $set('neighborhood', $data['bairro'] ?? null);
                $set('city', $data['localidade'] ?? null);
                $set('state', $data['uf'] ?? null);
                $set('city_code', $data['ibge'] ?? null);
                $set('country', 'Brasil');

                notify::success(
                    title: 'CEP encontrado',
                    message: 'Os dados do endereço foram preenchidos automaticamente.',
                );
            });
    }

    private static function formatPostalCode(?string $postalCode): string
    {
        $digits = preg_replace('/\D/', '', (string) $postalCode);

        if (strlen($digits) !== 8) {
            return $digits;
        }

        return substr($digits, 0, 5) . '-' . substr($digits, 5, 3);
    }
}
