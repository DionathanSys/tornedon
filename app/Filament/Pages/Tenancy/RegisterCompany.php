<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Company;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Notification\NotifyService as notify;

class RegisterCompany extends RegisterTenant
{
    public function getMaxWidth(): Width | string | null
    {
        return Width::SixExtraLarge;
    }

    public static function getLabel(): string
    {
        return 'Registrar Empresa';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informações da Empresa')
                    ->description('Preencha os dados básicos da sua empresa')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Nome da Empresa')
                                    ->placeholder('Ex: Tecnologia Brasil Ltda')
                                    ->required()
                                    ->minLength(3)
                                    ->maxLength(255)
                                    ->autocomplete(false)
                                    ->columnSpanFull(),
                                TextInput::make('email')
                                    ->label('Email')
                                    ->email()
                                    ->placeholder('contato@empresa.com.br')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                                TextInput::make('phone')
                                    ->label('Telefone')
                                    ->placeholder('(11) 3000-0000')
                                    ->tel()
                                    ->maxLength(20)
                                    ->columnSpan(['md' => 2, 'lg' => 2]),
                            ]),
                    ]),

                Section::make('Endereço')
                    ->collapsible()
                    ->collapsed()
                    ->description('Dados de localização (opcional)')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('address.street')
                                    ->label('Rua')
                                    ->placeholder('Rua das Flores, 123')
                                    ->maxLength(255)
                                    ->columnSpan(['md' => 2]),
                                TextInput::make('address.number')
                                    ->label('Número')
                                    ->numeric()
                                    ->maxLength(20)
                                    ->columnSpan(['md' => 1]),
                                TextInput::make('address.complement')
                                    ->label('Complemento')
                                    ->placeholder('Apto 101')
                                    ->maxLength(255)
                                    ->columnSpan(['md' => 1]),
                                TextInput::make('address.city')
                                    ->label('Cidade')
                                    ->placeholder('São Paulo')
                                    ->maxLength(255)
                                    ->columnSpan(['md' => 1]),
                                TextInput::make('address.state')
                                    ->label('Estado')
                                    ->placeholder('SP')
                                    ->maxLength(2)
                                    ->columnSpan(['md' => 1]),
                                TextInput::make('address.zip_code')
                                    ->label('CEP')
                                    ->placeholder('01000-000')
                                    ->maxLength(10)
                                    ->columnSpan(['md' => 1]),
                            ]),
                    ]),

                Section::make('Documentos')
                    ->collapsible()
                    ->collapsed()
                    ->description('Dados fiscais e de registro (opcional)')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('municipal_tax_id')
                                    ->label('Inscrição Municipal')
                                    ->placeholder('12345678/0001-90')
                                    ->maxLength(50)
                                    ->columnSpan(['md' => 1]),
                                TextInput::make('state_tax_id')
                                    ->label('Inscrição Estadual')
                                    ->placeholder('123456789012345')
                                    ->maxLength(50)
                                    ->columnSpan(['md' => 1]),
                                FileUpload::make('certificate')
                                    ->label('Certificado Digital')
                                    ->disabled()
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    protected function handleRegistration(array $data): Company
    {
        // Preparar dados de endereço
        $address = array_filter([
            'street' => $data['address']['street'] ?? null,
            'number' => $data['address']['number'] ?? null,
            'complement' => $data['address']['complement'] ?? null,
            'city' => $data['address']['city'] ?? null,
            'state' => $data['address']['state'] ?? null,
            'zip_code' => $data['address']['zip_code'] ?? null,
        ]);

        // Criar a empresa
        $company = DB::transaction(function () use ($data, $address) {
            $company = Company::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'address' => !empty($address) ? $address : null,
                'municipal_tax_id' => $data['municipal_tax_id'] ?? null,
                'state_tax_id' => $data['state_tax_id'] ?? null,
                'certificate' => $data['certificate'] ?? null,
                'is_active' => true,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            // Associar o usuário atual à empresa como owner
            $company->users()->attach(Auth::id(), [
                'role' => 'owner',
                'is_active' => true,
            ]);
            return $company;
        });

        if (!$company) {
            notify::error('Erro ao registrar empresa', 'Ocorreu um erro inesperado ao criar a empresa. Por favor, tente novamente.');
        }

        
        return $company;
    }
}
