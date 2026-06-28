<?php

namespace App\Filament\Management\Pages;

use App\Services\Cnpj\CnpjConsultationService;
use App\Services\Cnpj\CnpjProviderSettingsRepository;
use BackedEnum;
use Filament\Forms;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class CnpjProviderSettingsPage extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected string $view = 'filament.management.pages.cnpj-provider-settings';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationLabel = 'Providers de CNPJ';

    protected static ?string $title = 'Configuração de Providers de CNPJ';

    protected static ?string $slug = 'cnpj-providers';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public function mount(CnpjProviderSettingsRepository $repository): void
    {
        $this->form->fill([
            'providers' => $repository->all(),
            'consultation' => $this->defaultConsultationState(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Ordem e disponibilidade dos providers')
                    ->description('Reordene os providers para definir a prioridade do fallback e habilite apenas os necessários.')
                    ->icon(Heroicon::OutlinedArrowsUpDown)
                    ->schema([
                        Forms\Components\Repeater::make('providers')
                            ->label('Providers')
                            ->reorderable()
                            ->addable(false)
                            ->deletable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['label'] ?? $state['name'] ?? 'Provider'))
                            ->schema([
                                Forms\Components\Hidden::make('name'),
                                Forms\Components\Hidden::make('label'),
                                Forms\Components\Placeholder::make('provider_name')
                                    ->label('Provider')
                                    ->content(fn (array $state): string => (string) ($state['label'] ?? $state['name'] ?? 'Provider')),
                                Forms\Components\Toggle::make('enabled')
                                    ->label('Habilitado')
                                    ->inline(false)
                                    ->default(false),
                                Forms\Components\TextInput::make('base_url')
                                    ->label('Base URL')
                                    ->url()
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('timeout')
                                    ->label('Timeout (segundos)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(120)
                                    ->default(15),
                                Forms\Components\TextInput::make('rate_limit.max_attempts')
                                    ->label('Limite local de tentativas')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(5),
                                Forms\Components\TextInput::make('rate_limit.decay_seconds')
                                    ->label('Janela do rate limit (segundos)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(60),
                                Forms\Components\KeyValue::make('headers')
                                    ->label('Headers')
                                    ->keyLabel('Header')
                                    ->valueLabel('Valor')
                                    ->addActionLabel('Adicionar header')
                                    ->columnSpanFull(),
                            ])
                            ->columns(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Teste de consulta')
                    ->description('Use esta seção para validar a configuração atual e inspecionar o payload retornado pelo provider utilizado.')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->schema([
                        Forms\Components\TextInput::make('consultation.cnpj')
                            ->label('CNPJ para consulta')
                            ->placeholder('12.345.678/0001-95')
                            ->maxLength(18),
                        Forms\Components\TextInput::make('consultation.provider')
                            ->label('Provider utilizado')
                            ->readOnly()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('consultation.message')
                            ->label('Status da consulta')
                            ->readOnly()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        Forms\Components\CodeEditor::make('consultation.result')
                            ->label('Resultado bruto')
                            ->disabled()
                            ->dehydrated(false)
                            ->language(Language::Json)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(CnpjProviderSettingsRepository $repository): void
    {
        $repository->save((array) ($this->data['providers'] ?? []));

        $this->data['providers'] = $repository->all();
        $this->form->fill($this->data);

        Notification::make()
            ->title('Configurações de providers de CNPJ salvas com sucesso!')
            ->success()
            ->send();
    }

    public function consult(CnpjConsultationService $service): void
    {
        $cnpj = trim((string) data_get($this->data, 'consultation.cnpj', ''));

        if ($cnpj === '') {
            Notification::make()
                ->title('Informe um CNPJ para consulta')
                ->warning()
                ->send();

            return;
        }

        $result = $service->consult($cnpj, [
            'source' => 'management_cnpj_provider_settings_page',
        ]);

        data_set($this->data, 'consultation.provider', (string) data_get($service->getData(), 'provider', ''));
        data_set($this->data, 'consultation.message', $service->getMessage());
        data_set(
            $this->data,
            'consultation.result',
            json_encode(
                $result?->toArray() ?? data_get($service->getData(), 'raw', []),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) ?: '{}',
        );

        $this->form->fill($this->data);

        $notification = Notification::make()
            ->title($result ? 'Consulta de CNPJ realizada com sucesso' : 'Consulta de CNPJ concluída com erro')
            ->body($service->getMessage());

        if ($result) {
            $notification->success();
        } else {
            $notification->danger();
        }

        $notification->send();
    }

    /**
     * @return array<string, string>
     */
    private function defaultConsultationState(): array
    {
        return [
            'cnpj' => '',
            'provider' => '',
            'message' => '',
            'result' => '{}',
        ];
    }
}
