<?php

namespace App\Livewire\Bpo;

use App\Enums\AttachmentType;
use App\Filament\Bpo\Resources\AccountPayableResource;
use App\Filament\Bpo\Resources\AccountReceivableResource;
use App\Models\Attachment;
use App\Services\Access\CompanyAuthorization;
use App\Services\Attachments\AttachmentService;
use App\Services\Bpo\BpoAccessService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\WithFileUploads;

class RecordAttachments extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $module;

    #[Locked]
    public int $recordId;

    #[Locked]
    public int $companyId;

    #[Locked]
    public string $heading = 'Anexos do lançamento';

    #[Locked]
    public array $allowedTypes = [];

    #[Locked]
    public bool $uploadsEnabled = true;

    #[Locked]
    public bool $deletionsEnabled = true;

    public bool $showUpload = false;

    public array $files = [];

    public string $attachmentType = 'generic';

    public string $description = '';

    #[Locked]
    public ?int $editingId = null;

    public string $editingDescription = '';

    #[Locked]
    public array $preview = [];

    public function mount(string $module, int $recordId, string $heading = 'Anexos do lançamento', ?array $allowedTypes = null, bool $uploadsEnabled = true, bool $deletionsEnabled = true): void
    {
        $this->module = $module;
        $this->recordId = $recordId;
        $this->companyId = (int) Filament::getTenant()?->id;
        $this->heading = $heading;
        $this->uploadsEnabled = $uploadsEnabled;
        $this->deletionsEnabled = $deletionsEnabled;
        $owner = $this->resolveOwner();
        $this->allowedTypes = array_values(array_intersect($allowedTypes ?? ['generic', 'fiscal_document', 'contract'], $owner->allowedAttachmentTypes()));
        $this->attachmentType = in_array('generic', $this->allowedTypes, true) ? 'generic' : ($this->allowedTypes[0] ?? 'generic');
    }

    public function hydrate(): void
    {
        $this->resolveOwner();
    }

    public function canWrite(): bool
    {
        $owner = $this->resolveOwner();

        // A paid header may be read-only for amounts, but can still receive its supporting documents.
        return app(CompanyAuthorization::class)->allowsRecord(Filament::auth()->user(), Filament::getTenant(), $this->module.'.update', $owner);
    }

    public function openUpload(): void
    {
        abort_unless($this->uploadsEnabled && $this->allowedTypes !== [] && $this->canWrite(), 403);
        $this->resetValidation();
        $this->showUpload = true;
    }

    public function closeUpload(): void
    {
        $this->files = [];
        $this->description = '';
        $this->showUpload = false;
        $this->resetValidation();
    }

    public function uploadAttachments(): void
    {
        abort_unless($this->uploadsEnabled && $this->canWrite(), 403);
        $owner = $this->resolveOwner();
        $this->validate([
            'attachmentType' => ['required', Rule::in(array_intersect($this->allowedTypes, $owner->allowedAttachmentTypes()))],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $config = config('attachments.types.'.$this->attachmentType, []);
        $maxSize = $this->uploadLimit();
        $fileRules = ['required', 'file', 'max:'.$maxSize];
        if ($config['allowed_mimes'] ?? []) {
            $fileRules[] = 'mimetypes:'.implode(',', $config['allowed_mimes']);
        }
        $this->validate(['files' => ['required', 'array', 'min:1', 'max:10'], 'files.*' => $fileRules], [
            'files.required' => 'Selecione pelo menos um arquivo.', 'files.min' => 'Selecione pelo menos um arquivo.',
            'files.max' => 'Envie até 10 arquivos por vez.',
            'files.*.max' => 'O arquivo excede o limite de '.Number::fileSize($maxSize * 1024).'.',
            'files.*.mimetypes' => 'O formato do arquivo não corresponde ao tipo de anexo selecionado.',
        ]);
        $remaining = $this->files;
        try {
            foreach ($this->files as $index => $file) {
                app(AttachmentService::class)->upload($owner, $file, $this->attachmentType, ['description' => $this->description]);
                unset($remaining[$index]);
            }
        } catch (\Throwable $exception) {
            report($exception);
            $this->files = array_values($remaining);
            Notification::make()->title('Alguns arquivos não puderam ser enviados')->body('Os anexos concluídos estão na lista. Tente novamente apenas os arquivos restantes.')->danger()->send();

            return;
        }
        $this->closeUpload();
        Notification::make()->title('Anexos enviados')->success()->send();
    }

    public function viewAttachment(int $id): void
    {
        $attachment = $this->findAttachment($id);
        abort_unless($attachment->isPreviewable(), 404);
        $this->preview = ['id' => $attachment->id, 'name' => $attachment->original_name, 'description' => $attachment->description,
            'image' => $attachment->isPreviewableImage(), 'url' => $attachment->preview_url, 'download' => $attachment->url];
        $this->dispatch('open-modal', id: $this->previewModalId());
    }

    public function editDescription(int $id): void
    {
        abort_unless($this->uploadsEnabled && $this->canWrite(), 403);
        $attachment = $this->findAttachment($id);
        $this->editingId = $attachment->id;
        $this->editingDescription = $attachment->description ?? '';
        $this->resetValidation('editingDescription');
    }

    public function cancelDescription(): void
    {
        $this->editingId = null;
        $this->editingDescription = '';
        $this->resetValidation('editingDescription');
    }

    public function saveDescription(): void
    {
        abort_unless($this->uploadsEnabled && $this->editingId && $this->canWrite(), 403);
        $this->validate(['editingDescription' => ['nullable', 'string', 'max:2000']]);
        $attachment = $this->findAttachment($this->editingId);
        $attachment->update(['description' => $this->editingDescription]);
        if (($this->preview['id'] ?? null) === $attachment->id) {
            $this->preview['description'] = $attachment->description;
        }
        $this->cancelDescription();
        Notification::make()->title('Descrição do anexo atualizada')->success()->send();
    }

    public function deleteAttachment(int $id): void
    {
        abort_unless($this->deletionsEnabled && $this->canWrite(), 403);
        $attachment = $this->findAttachment($id);
        app(AttachmentService::class)->delete($attachment);
        if (($this->preview['id'] ?? null) === $id) {
            $this->preview = [];
            $this->dispatch('close-modal', id: $this->previewModalId());
        }
        if ($this->editingId === $id) {
            $this->cancelDescription();
        }
        Notification::make()->title('Anexo removido')->success()->send();
    }

    public function previewModalId(): string
    {
        return 'bpo-attachments-preview-'.$this->getId();
    }

    private function resolveOwner(): Model
    {
        $resource = match ($this->module) {
            'account_payables' => AccountPayableResource::class,
            'account_receivables' => AccountReceivableResource::class,
            default => abort(404),
        };
        $company = Filament::getTenant();
        $user = Filament::auth()->user();
        abort_unless($company && $user && $this->companyId === (int) $company->id
            && app(BpoAccessService::class)->canAccessCompany($user, $company), 403);
        $owner = $resource::getEloquentQuery()->where('company_id', $this->companyId)->find($this->recordId);
        abort_unless($owner && $resource::canView($owner), 404);

        return $owner;
    }

    private function findAttachment(int $id): Attachment
    {
        $attachment = $this->resolveOwner()->currentAttachments()->where('company_id', $this->companyId)->find($id);
        abort_unless($attachment, 404);

        return $attachment;
    }

    private function uploadLimit(): int
    {
        $limit = (int) config('attachments.types.'.$this->attachmentType.'.max_size', 20480);
        $rules = FileUploadConfiguration::rules();
        foreach (is_array($rules) ? $rules : explode('|', $rules) as $rule) {
            if (is_string($rule) && preg_match('/^max:(\d+)$/', $rule, $match)) {
                $limit = min($limit, (int) $match[1]);
            }
        }

        return $limit;
    }

    public function render(): View
    {
        $owner = $this->resolveOwner();
        $canWrite = $this->canWrite();
        $attachments = $owner->currentAttachments()->where('company_id', $this->companyId)->with('uploader:id,name')->latest()->orderByDesc('id')->get();
        $types = collect(AttachmentType::cases())->filter(fn ($type) => in_array($type->value, $this->allowedTypes, true));
        $acceptedMimes = config('attachments.types.'.$this->attachmentType.'.allowed_mimes', []);
        $pendingFiles = collect($this->files)->filter(fn ($file) => $file instanceof UploadedFile)
            ->map(fn ($file) => ['name' => $file->getClientOriginalName(), 'size' => Number::fileSize($file->getSize())]);

        return view('livewire.bpo.record-attachments', ['attachments' => $attachments, 'types' => $types, 'canWrite' => $canWrite,
            'acceptedMimes' => implode(',', $acceptedMimes), 'limitLabel' => Number::fileSize($this->uploadLimit() * 1024), 'pendingFiles' => $pendingFiles]);
    }
}
