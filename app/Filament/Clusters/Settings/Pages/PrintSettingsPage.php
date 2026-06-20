<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Models\CompanyPreference;
use App\Support\Fiscal\NfsePrintSettings;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

class PrintSettingsPage extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-printer';

    protected string $view = 'filament.clusters.settings.pages.print-settings';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationLabel = 'Configurações de Impressão';

    protected static ?string $title = 'Configurações de Impressão';

    protected static ?int $navigationSort = 9;

    public ?array $data = [];

    public function mount(): void
    {
        $companyId = Filament::getTenant()?->id;
        $settings = CompanyPreference::get(NfsePrintSettings::PREFERENCE_KEY, $companyId, []);

        $this->form->fill([
            'description_fields' => $this->mapTokensToRepeaterState(data_get($settings, 'documento_fiscal_nfse.description', [])),
            'additional_information_fields' => $this->mapTokensToRepeaterState(data_get($settings, 'documento_fiscal_nfse.additional_information', [])),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Documento Fiscal - NFSe')
                    ->description('Defina a ordem dos campos automáticos usados como padrão na descrição e nas informações adicionais da NFS-e. Sem configuração, o sistema mantém o comportamento atual.')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Forms\Components\Repeater::make('description_fields')
                            ->label('Description')
                            ->defaultItems(0)
                            ->addActionLabel('Adicionar campo')
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => NfsePrintSettings::fieldOptions()[$state['field'] ?? ''] ?? 'Campo')
                            ->helperText('Os campos são concatenados com " | " e o resultado final é limitado a 2000 caracteres.')
                            ->schema([
                                Forms\Components\Select::make('field')
                                    ->label('Campo')
                                    ->options(NfsePrintSettings::fieldOptions())
                                    ->required()
                                    ->native(false)
                                    ->searchable(),
                            ])
                            ->columnSpanFull(),

                        Forms\Components\Repeater::make('additional_information_fields')
                            ->label('Additional Information')
                            ->defaultItems(0)
                            ->addActionLabel('Adicionar campo')
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => NfsePrintSettings::fieldOptions()[$state['field'] ?? ''] ?? 'Campo')
                            ->helperText('Os campos são concatenados com quebra de linha e o resultado final é limitado a 2000 caracteres.')
                            ->schema([
                                Forms\Components\Select::make('field')
                                    ->label('Campo')
                                    ->options(NfsePrintSettings::fieldOptions())
                                    ->required()
                                    ->native(false)
                                    ->searchable(),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $companyId = Filament::getTenant()?->id;

        CompanyPreference::set(NfsePrintSettings::PREFERENCE_KEY, [
            'documento_fiscal_nfse' => [
                'description' => $this->normalizeRepeaterFields($this->data['description_fields'] ?? []),
                'additional_information' => $this->normalizeRepeaterFields($this->data['additional_information_fields'] ?? []),
            ],
        ], $companyId);

        Notification::make()
            ->title('Configurações de impressão salvas com sucesso!')
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return true;
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, array{field: string}>
     */
    private function mapTokensToRepeaterState(array $tokens): array
    {
        return collect($tokens)
            ->map(fn (string $token): array => ['field' => $token])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function normalizeRepeaterFields(array $rows): array
    {
        return collect($rows)
            ->map(fn (array $row): string => trim((string) ($row['field'] ?? '')))
            ->filter(fn (string $field): bool => $field !== '' && array_key_exists($field, NfsePrintSettings::fieldOptions()))
            ->values()
            ->all();
    }
}
