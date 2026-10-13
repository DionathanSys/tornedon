<?php

namespace App\Livewire;

use App\Enums\AttachmentType;
use App\Models\Attachment;
use App\Models\ServiceOrder;
use App\Services\Attachments\AttachmentService;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Mary\Traits\Toast;

class OperationAttachments extends Component
{
    use Toast, WithFileUploads;

    #[Locked]
    public string $orderId = '';

    public array $files = [];

    public string $attachmentType = 'generic';

    public bool $showUpload = false;

    public bool $showDeleteModal = false;

    public bool $showPreviewModal = false;

    #[Locked]
    public ?int $deletingId = null;

    #[Locked]
    public string $deletingName = '';

    #[Locked]
    public array $preview = [];

    public function mount(int|string $orderId): void
    {
        $this->orderId = (string) $orderId;
        $this->tenantOrder();
    }

    public function uploadAttachments(): void
    {
        $order = $this->tenantOrder();
        $this->validate(['attachmentType' => ['required', Rule::in($order->allowedAttachmentTypes())]]);
        $config = config('attachments.types.'.$this->attachmentType, []);
        $maxSize = $config['max_size'] ?? 20480;
        $rules = ['required', 'file', 'max:'.$maxSize];

        if ($config['allowed_mimes'] ?? []) {
            $rules[] = 'mimetypes:'.implode(',', $config['allowed_mimes']);
        }

        $this->validate(['files' => ['required', 'array', 'min:1', 'max:10'], 'files.*' => $rules], [
            'files.required' => 'Selecione pelo menos um arquivo.',
            'files.max' => 'Envie até 10 arquivos por vez.',
            'files.*.max' => 'Um dos arquivos excede o limite de '.Number::fileSize($maxSize * 1024).' por arquivo deste tipo de anexo.',
            'files.*.mimetypes' => 'Um dos arquivos não corresponde ao tipo de anexo selecionado.',
        ]);

        $remaining = $this->files;

        try {
            foreach ($this->files as $index => $file) {
                app(AttachmentService::class)->upload($order, $file, $this->attachmentType);
                unset($remaining[$index]);
            }
        } catch (\Throwable $exception) {
            report($exception);
            $this->files = array_values($remaining);
            $this->error('Não foi possível enviar todos os anexos. Os arquivos restantes podem ser enviados novamente.');

            return;
        }

        $this->files = [];
        $this->showUpload = false;
        $this->success('Anexos enviados com sucesso.');
    }

    public function viewAttachment(int $id): void
    {
        $attachment = $this->tenantAttachment($id);
        abort_unless($attachment->isPreviewable(), 404);
        $this->preview = [
            'id' => $attachment->id,
            'name' => $attachment->original_name, 'url' => $attachment->preview_url,
            'image' => $attachment->isPreviewableImage(),
        ];
        $this->showPreviewModal = true;
    }

    public function requestAttachmentDeletion(int $id): void
    {
        $attachment = $this->tenantAttachment($id);
        $this->deletingId = $attachment->id;
        $this->deletingName = $attachment->original_name;
        $this->showDeleteModal = true;
    }

    public function deleteAttachment(): void
    {
        abort_unless($this->showDeleteModal && $this->deletingId, 403);
        $attachment = $this->tenantAttachment($this->deletingId);
        app(AttachmentService::class)->delete($attachment);
        if (($this->preview['id'] ?? null) === $attachment->id) {
            $this->preview = [];
            $this->showPreviewModal = false;
        }
        $this->showDeleteModal = false;
        $this->deletingId = null;
        $this->deletingName = '';
        $this->success('Anexo removido.');
    }

    private function tenantOrder(): ServiceOrder
    {
        $order = ServiceOrder::query()->where('company_id', Filament::getTenant()?->getKey())->whereKey($this->orderId)->first();
        abort_unless($order, 404);

        return $order;
    }

    private function tenantAttachment(int $id): Attachment
    {
        $attachment = $this->tenantOrder()->currentAttachments()->where('company_id', Filament::getTenant()?->getKey())->whereKey($id)->first();
        abort_unless($attachment, 404);

        return $attachment;
    }

    public function render(): View
    {
        $order = $this->tenantOrder();
        $types = collect(AttachmentType::cases())->filter(fn ($type) => in_array($type->value, $order->allowedAttachmentTypes(), true))
            ->map(fn ($type): array => ['id' => $type->value, 'name' => $type->getLabel()])->values()->all();
        $attachments = $order->currentAttachments()->where('company_id', $order->company_id)->with('uploader:id,name')->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(fn (Attachment $attachment): array => [
                'id' => $attachment->id, 'name' => $attachment->original_name,
                'type' => $attachment->type->getLabel(), 'size' => Number::fileSize($attachment->size_bytes),
                'date' => $attachment->created_at->format('d/m/Y H:i'), 'uploader' => $attachment->uploader?->name,
                'url' => $attachment->url, 'previewable' => $attachment->isPreviewable(),
            ])->all();

        $typeConfig = config('attachments.types.'.$this->attachmentType, []);
        $maxSizeLabel = Number::fileSize(($typeConfig['max_size'] ?? 20480) * 1024);
        $allowedMimes = $typeConfig['allowed_mimes'] ?? [];
        $uploadHint = 'Até 10 arquivos por envio. Máximo de '.$maxSizeLabel.' por arquivo.';
        if ($allowedMimes) {
            $uploadHint .= ' Formatos: '.implode(', ', array_map(fn (string $mime): string => match ($mime) {
                'image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/webp' => 'WebP',
                'application/xml', 'text/xml' => 'XML', 'application/pdf' => 'PDF',
                'application/msword' => 'DOC',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
                default => $mime,
            }, array_unique($allowedMimes))).'.';
        }

        $uploadLimits = collect(config('attachments.types', []))->map(fn (array $config): int => ($config['max_size'] ?? 20480) * 1024)->all();

        return view('livewire.operation-attachments', compact('types', 'attachments', 'uploadHint', 'allowedMimes', 'uploadLimits'));
    }
}
