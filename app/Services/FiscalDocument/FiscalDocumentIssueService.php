<?php

namespace App\Services\FiscalDocument;

use App\Enums\AttachmentType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentIssue;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use App\Services\Attachments\AttachmentService;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FiscalDocumentIssueService
{
    public function canManage(?User $user, FiscalDocument $document): bool
    {
        if (! $user || ! $user->canAccessTenant($document->company)) {
            return false;
        }
        $access = app(CompanyAuthorization::class);
        $membership = $access->membership($user, $document->company);

        return $membership && (($membership->access_mode === 'legacy' && $membership->role === 'owner')
            || in_array('company', $access->scopes($user, $document->company, 'team.manage'), true));
    }

    public function open(FiscalDocument $document, string $reason, string $message, ?int $userId = null): ?FiscalDocumentIssue
    {
        return DB::transaction(function () use ($document, $reason, $message, $userId): ?FiscalDocumentIssue {
            $document = FiscalDocument::where('company_id', $document->company_id)->lockForUpdate()->findOrFail($document->id);
            if ($document->isFiscalCancellationConfirmed()) {
                return null;
            }
            $issue = $document->fiscalIssues()->where('active_slot', 1)->first();
            $entry = ['at' => now()->toIso8601String(), 'event' => 'cancellation_blocked', 'reason' => $reason, 'message' => $message, 'user_id' => $userId];
            if ($issue) {
                $issue->update(['reason' => $reason, 'message' => $message, 'status' => in_array($reason, ['cancellation_uncertain', 'cancellation_confirmation_pending'], true) ? 'awaiting_confirmation' : $issue->status, 'history' => [...$issue->history, $entry]]);
            } else {
                $issue = $document->fiscalIssues()->create(['company_id' => $document->company_id, 'reason' => $reason, 'message' => $message, 'status' => $reason === 'cancellation_uncertain' ? 'awaiting_confirmation' : 'open', 'active_slot' => 1, 'history' => [$entry], 'updated_by' => $userId]);
            }

            return $issue;
        });
    }

    public function manage(FiscalDocument $document, User $user, array $data, ?UploadedFile $file = null): FiscalDocumentIssue
    {
        abort_unless($this->canManage($user, $document), 403);
        $validated = Validator::make($data + ['file' => $file], [
            'resolution' => ['required', Rule::in(['follow_up', 'external_cancellation_reported', 'keep_document'])],
            'notes' => ['required', 'string', 'min:15', 'max:5000'],
            'reference' => ['nullable', 'string', 'max:255'],
            'evidence_attachment_id' => ['nullable', 'integer'],
            'file' => ['nullable', 'file', 'mimes:pdf,xml,txt,jpg,jpeg,png', 'max:10240'],
        ])->validate();
        if ($file) {
            $attachment = app(AttachmentService::class)->upload($document, $file, AttachmentType::GENERIC, ['description' => 'Comprovante de tratamento fiscal']);
            $validated['evidence_attachment_id'] = $attachment->id;
        }

        return DB::transaction(function () use ($document, $user, $validated): FiscalDocumentIssue {
            $document = FiscalDocument::where('company_id', $document->company_id)->lockForUpdate()->findOrFail($document->id);
            abort_unless($this->canManage($user, $document), 403);
            $evidenceId = $validated['evidence_attachment_id'] ?? null;
            if ($evidenceId && ! $document->attachments()->where('company_id', $document->company_id)->whereKey($evidenceId)->exists()) {
                throw ValidationException::withMessages(['evidence_attachment_id' => 'O comprovante deve ser um anexo desta nota e empresa.']);
            }
            if ($validated['resolution'] === 'external_cancellation_reported' && (! $evidenceId || empty($validated['reference']))) {
                throw ValidationException::withMessages(['evidence_attachment_id' => 'Informe a referência/protocolo e anexe o comprovante do procedimento externo.']);
            }
            $issue = $document->fiscalIssues()->where('active_slot', 1)->first();
            if (! $issue) {
                $eligibility = app(FiscalCancellationPolicy::class)->evaluate($document);
                if ($eligibility['allowed'] || $eligibility['reason'] === 'not_authorized') {
                    throw ValidationException::withMessages(['notes' => 'Não existe pendência fiscal ativa para tratar.']);
                }
                $issue = $this->open($document, $eligibility['reason'], $eligibility['message'], $user->id);
            }
            if (! $issue) {
                throw ValidationException::withMessages(['notes' => 'A nota já foi cancelada; atualize a tela.']);
            }
            if ($issue->status === 'awaiting_confirmation' && $validated['resolution'] === 'keep_document') {
                throw ValidationException::withMessages(['resolution' => 'Consulte o órgão emissor antes de encerrar uma solicitação com resultado incerto.']);
            }
            $entry = ['at' => now()->toIso8601String(), 'event' => $validated['resolution'], 'notes' => $validated['notes'], 'reference' => $validated['reference'] ?? null, 'evidence_attachment_id' => $evidenceId, 'user_id' => $user->id];
            $resolved = $validated['resolution'] === 'keep_document';
            $issue->update([
                'status' => $resolved ? 'resolved' : ($validated['resolution'] === 'external_cancellation_reported' || $issue->status === 'awaiting_confirmation' ? 'awaiting_confirmation' : 'open'),
                'active_slot' => $resolved ? null : 1,
                'resolved_at' => $resolved ? now() : null,
                'updated_by' => $user->id,
                'evidence_attachment_id' => $evidenceId ?? $issue->evidence_attachment_id,
                'history' => [...$issue->history, $entry],
            ]);
            app(AuditRecorder::class)->recordModelEvent($document, 'fiscal_document.issue_treated', 'Tratamento de pendência fiscal registrado', actorUserId: $user->id, metadata: ['issue_id' => $issue->id, ...$entry]);

            return $issue;
        });
    }

    public function reconcileCancellation(FiscalDocument $document): void
    {
        if (! $document->isFiscalCancellationConfirmed()) {
            return;
        }
        DB::transaction(function () use ($document): void {
            foreach ($document->fiscalIssues()->where('active_slot', 1)->lockForUpdate()->get() as $issue) {
                $issue->update(['status' => 'resolved', 'active_slot' => null, 'resolved_at' => now(), 'history' => [...$issue->history, ['at' => now()->toIso8601String(), 'event' => 'cancellation_confirmed_by_integration']]]);
            }
        });
    }

    public function reconcileAuthorizedConsultation(FiscalDocument $document): void
    {
        if (! ($document->isNfse() ? $document->isNfseAuthorized() : $document->isNfeAuthorized())) {
            return;
        }
        DB::transaction(function () use ($document): void {
            foreach ($document->fiscalIssues()->where('active_slot', 1)->where('status', 'awaiting_confirmation')->lockForUpdate()->get() as $issue) {
                $issue->update(['status' => 'open', 'reason' => 'cancellation_not_confirmed', 'history' => [...$issue->history, ['at' => now()->toIso8601String(), 'event' => 'consultation_still_authorized']]]);
            }
        });
    }
}
