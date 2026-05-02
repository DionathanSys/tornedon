<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Company;
use App\Services\Company\CompanyCertificateStorageService;
use App\Services\Company\CompanyLogoStorageService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

class CompanySettings extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected string $view = 'filament.clusters.settings.pages.company-settings';

    // protected static ?string $cluster = SettingsCluster::class;

    protected static string | UnitEnum | null $navigationGroup = 'Configurações';

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
            'certificate'      => $company->certificate ? [$company->certificate] : [],
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

                Section::make('Certificado Digital')
                    ->description('Arquivo A1 (.pfx ou .p12) usado para consultar DF-e diretamente na SEFAZ.')
                    ->icon('heroicon-o-shield-check')
                    ->schema([
                        Forms\Components\FileUpload::make('certificate')
                            ->label('Certificado A1')
                            ->disk('local')
                            ->directory(fn () => 'certificates/' . (Filament::getTenant()?->id ?? 'tmp'))
                            ->acceptedFileTypes([
                                'application/x-pkcs12',
                                'application/x-pkcs7-certificates',
                                'application/octet-stream',
                            ])
                            ->maxSize(5120)
                            ->downloadable()
                            ->openable()
                            ->previewable(false)
                            ->getUploadedFileNameForStorageUsing(
                                fn (TemporaryUploadedFile $file): string => 'certificate_' . now()->format('YmdHis') . '.' . strtolower($file->getClientOriginalExtension() ?: 'pfx')
                            )
                            ->helperText('Envie um arquivo .pfx ou .p12. A senha do certificado é configurada na tela "Configurações NF-e".')
                            ->columnSpanFull()
                            ->rules(['extensions:pfx,p12']),
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
            $logoService = app(CompanyLogoStorageService::class);
            $certificateService = app(CompanyCertificateStorageService::class);
            $newLogoPath = is_array($data['logo_path']) ? (reset($data['logo_path']) ?: null) : ($data['logo_path'] ?: null);
            $newCertificatePath = is_array($data['certificate'] ?? null) ? (reset($data['certificate']) ?: null) : ($data['certificate'] ?? null);
            $documentNumber = $this->normalizeDocumentNumber($data['document_number'] ?? null);
            $certificateChanged = $newCertificatePath !== $company->certificate;

            Log::info('CompanySettings: salvando configuracoes da empresa', [
                'company_id' => $company->id,
                'user_id' => Auth::id(),
                'certificate_changed' => $certificateChanged,
                'current_certificate' => $this->describeCertificatePath($company->certificate),
                'new_certificate' => $this->describeCertificatePath($newCertificatePath),
            ]);

            if ($newLogoPath !== $company->logo_path) {
                $logoService->save($company, $newLogoPath);
            }

            if ($newCertificatePath !== $company->certificate) {
                Log::info('CompanySettings: iniciando troca do certificado da empresa', [
                    'company_id' => $company->id,
                    'user_id' => Auth::id(),
                    'current_certificate' => $this->describeCertificatePath($company->certificate),
                    'new_certificate' => $this->describeCertificatePath($newCertificatePath),
                ]);

                $certificateService->save($company, $newCertificatePath);
            }

            $company->update([
                'name'            => $data['name'],
                'email'           => $data['email'] ?? null,
                'phone'           => $data['phone'] ?? null,
                'document_number' => $documentNumber,
                'updated_by'      => Auth::id(),
            ]);

            Log::info('CompanySettings: configuracoes da empresa salvas com sucesso', [
                'company_id' => $company->id,
                'user_id' => Auth::id(),
                'certificate_changed' => $certificateChanged,
                'certificate' => $this->describeCertificatePath($company->fresh()->certificate),
            ]);

            Notification::make()
                ->title('Configurações salvas')
                ->body('As informações da empresa foram atualizadas.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Log::error('CompanySettings: erro ao salvar configuracoes da empresa', [
                'company_id' => $company->id,
                'user_id' => Auth::id(),
                'certificate' => $this->describeCertificatePath($company->certificate),
                'erro' => $e->getMessage(),
            ]);

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

    /**
     * @return array{name:?string,extension:?string,path_hash:?string}
     */
    private function describeCertificatePath(?string $path): array
    {
        if (! filled($path)) {
            return [
                'name' => null,
                'extension' => null,
                'path_hash' => null,
            ];
        }

        return [
            'name' => basename($path),
            'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'path_hash' => substr(sha1($path), 0, 12),
        ];
    }
}
