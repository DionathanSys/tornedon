<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Company;
use App\Services\Company\CompanyLogoStorageService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class CompanySettings extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected string $view = 'filament.clusters.settings.pages.company-settings';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $navigationLabel = 'Identidade da Empresa';

    protected static ?string $title = 'Identidade da Empresa';

    protected static ?int $navigationSort = 0;

    public ?array $data = [];

    public function mount(): void
    {
        /** @var Company|null $company */
        $company = Filament::getTenant();

        if (! $company) {
            $this->form->fill([]);
            return;
        }

        $this->form->fill([
            'name'             => $company->name,
            'email'            => $company->email,
            'phone'            => $company->phone,
            'document_number'  => $company->document_number,
            'logo_path'        => $company->logo_path ? [$company->logo_path] : [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Logo da Empresa')
                    ->description('Imagem exibida nos documentos PDF gerados pelo sistema.')
                    ->icon('heroicon-o-photo')
                    ->schema([
                        Forms\Components\FileUpload::make('logo_path')
                            ->label('Logo')
                            ->image()
                            ->disk('public')
                            ->directory(fn() => 'logos/' . (Filament::getTenant()?->id ?? 'tmp'))
                            ->getUploadedFileNameForStorageUsing(
                                fn(TemporaryUploadedFile $file): string => 'logo_' . now()->format('YmdHis') . '.' . $file->getClientOriginalExtension()
                            )
                            ->imagePreviewHeight('80')
                            ->maxSize(2048)
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'])
                            ->helperText('PNG, JPG, WebP ou SVG. Tamanho máximo: 2 MB.')
                            ->columnSpanFull()
                            ->automaticallyResizeImagesToWidth('100')
                            ->automaticallyResizeImagesToHeight('100'),
                    ]),

                Section::make('Dados da Empresa')
                    ->description('Informações básicas exibidas nos documentos.')
                    ->icon('heroicon-o-identification')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Razão Social / Nome')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('document_number')
                            ->label('CNPJ / CPF')
                            ->maxLength(18),
                        Forms\Components\TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->label('Telefone')
                            ->tel()
                            ->maxLength(20),
                    ])
                    ->columns(['md' => 2, 'lg' => 2]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        /** @var Company|null $company */
        $company = Filament::getTenant();

        if (! $company) {
            Notification::make()
                ->title('Erro')
                ->body('Empresa não identificada.')
                ->danger()
                ->send();
            return;
        }

        $data = $this->form->getState();

        try {
            // Processa a logo
            $logoService = app(CompanyLogoStorageService::class);
            $newLogoPath = is_array($data['logo_path']) ? (reset($data['logo_path']) ?: null) : ($data['logo_path'] ?: null);
            $documentNumber = $this->normalizeDocumentNumber($data['document_number'] ?? null);

            if ($newLogoPath !== $company->logo_path) {
                $logoService->save($company, $newLogoPath);
            }

            // Atualiza os dados básicos
            $company->update([
                'name'            => $data['name'],
                'email'           => $data['email'] ?? null,
                'phone'           => $data['phone'] ?? null,
                'document_number' => $documentNumber,
                'updated_by'      => Auth::id(),
            ]);

            Notification::make()
                ->title('Configurações salvas')
                ->body('As informações da empresa foram atualizadas.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erro ao salvar')
                ->body('Ocorreu um erro: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function normalizeDocumentNumber(?string $documentNumber): ?string
    {
        if (! filled($documentNumber)) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', $documentNumber);

        return filled($normalized) ? $normalized : null;
    }
}
