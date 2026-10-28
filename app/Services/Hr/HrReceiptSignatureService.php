<?php

namespace App\Services\Hr;

use App\Enum\Audit\AuditSource;
use App\Models\Company;
use App\Models\HrEntry;
use App\Models\HrReceipt;
use App\Models\HrReceiptSignature;
use App\Models\HrReceiptSignatureLink;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class HrReceiptSignatureService
{
    public function __construct(private HrAccess $access) {}

    public function createLink(User $actor, Company $company, HrReceipt $receipt): array
    {
        return DB::transaction(function () use ($actor, $company, $receipt): array {
            [$entry, $receipt] = $this->lockReceipt($company->id, $receipt->id);
            $this->authorize($actor, $company, $entry, $receipt);
            $this->assertUnsigned($receipt);
            HrReceiptSignatureLink::where('company_id', $company->id)->where('hr_receipt_id', $receipt->id)->whereNull('used_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $token = bin2hex(random_bytes(32));
            $link = HrReceiptSignatureLink::create([
                'company_id' => $company->id, 'hr_receipt_id' => $receipt->id, 'created_by' => $actor->id,
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7),
            ]);
            app(AuditRecorder::class)->recordModelEvent($receipt, 'hr.receipt.signature_link_created', 'Link de assinatura do recibo de RH gerado', null,
                ['signature_link_id' => $link->id, 'expires_at' => $link->expires_at->toIso8601String()], $actor->id);

            return ['url' => route('hr.signature.show', ['token' => $token]), 'expires_at' => $link->expires_at, 'link' => $link];
        }, 3);
    }

    public function signInPerson(User $actor, Company $company, HrReceipt $receipt, array $data): HrReceiptSignature
    {
        return DB::transaction(function () use ($actor, $company, $receipt, $data): HrReceiptSignature {
            [$entry, $receipt] = $this->lockReceipt($company->id, $receipt->id);
            $this->authorize($actor, $company, $entry, $receipt);

            return $this->persist($receipt, $data, request(), $actor);
        }, 3);
    }

    /** Token possession authorizes only the receipt explicitly bound to this invitation. */
    public function publicContext(string $token): array
    {
        $link = HrReceiptSignatureLink::where('token_hash', hash('sha256', $token))->first();
        $this->assertUsableLink($link);
        $receipt = HrReceipt::withoutGlobalScopes()->where('company_id', $link->company_id)->find($link->hr_receipt_id);
        abort_unless($receipt && app(HrService::class)->receiptIsValid($receipt), 410, 'Este recibo não está mais disponível para assinatura.');
        $receipt->setRelation('signature', HrReceiptSignature::withoutGlobalScopes()->where('company_id', $link->company_id)->where('hr_receipt_id', $receipt->id)->first());

        return ['link' => $link, 'receipt' => $receipt];
    }

    public function signByToken(string $token, array $data, Request $request): HrReceiptSignature
    {
        return DB::transaction(function () use ($token, $data, $request): HrReceiptSignature {
            $candidate = HrReceiptSignatureLink::where('token_hash', hash('sha256', $token))->first();
            abort_unless($candidate, 410, 'Este link de assinatura não está mais disponível.');
            // Same lock order as in-person capture and link generation.
            [, $receipt] = $this->lockReceipt($candidate->company_id, $candidate->hr_receipt_id);
            $link = HrReceiptSignatureLink::whereKey($candidate->id)->lockForUpdate()->first();
            $this->assertUsableLink($link);
            abort_unless(app(HrService::class)->receiptIsValid($receipt), 410, 'Este recibo foi invalidado.');
            abort_if($link->used_at || HrReceiptSignature::withoutGlobalScopes()->where('hr_receipt_id', $receipt->id)->exists(), 409, 'Este recibo já foi assinado.');

            return $this->persist($receipt, $data, $request, link: $link);
        }, 3);
    }

    private function lockReceipt(int $companyId, int $receiptId): array
    {
        $receipt = HrReceipt::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($receiptId);
        $entry = HrEntry::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->findOrFail($receipt->hr_entry_id);
        app(HrService::class)->payments($entry)->whereKey($receipt->payment_id)->lockForUpdate()->first();

        return [$entry, HrReceipt::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->findOrFail($receiptId)];
    }

    private function authorize(User $actor, Company $company, HrEntry $entry, HrReceipt $receipt): void
    {
        $this->access->authorize($actor, $company, 'hr_entries.view', $entry);
        $this->access->authorize($actor, $company, 'hr_receipts.view', $receipt);
        $this->access->authorize($actor, $company, 'hr_receipts.sign', $receipt);
    }

    private function assertUsableLink(?HrReceiptSignatureLink $link): void
    {
        abort_unless($link && ! $link->revoked_at && $link->expires_at->isFuture()
            && Company::whereKey($link->company_id)->where('is_active', true)->exists(), 410, 'Este link de assinatura expirou ou não está mais disponível.');
    }

    private function assertUnsigned(HrReceipt $receipt): void
    {
        if (! app(HrService::class)->receiptIsValid($receipt)) {
            throw ValidationException::withMessages(['signature' => 'Não é possível assinar um recibo invalidado.']);
        }
        if (HrReceiptSignature::withoutGlobalScopes()->where('hr_receipt_id', $receipt->id)->exists()) {
            throw ValidationException::withMessages(['signature' => 'Este recibo já possui assinatura.']);
        }
    }

    private function persist(HrReceipt $receipt, array $data, Request $request, ?User $actor = null, ?HrReceiptSignatureLink $link = null): HrReceiptSignature
    {
        $this->assertUnsigned($receipt);
        $validated = Validator::make($data, [
            'signed_by_name' => ['required', 'string', 'max:255'],
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:2500000'],
        ])->validate();
        $binary = base64_decode(substr($validated['signature'], strlen('data:image/png;base64,')), true);
        $image = $binary !== false ? @getimagesizefromstring($binary) : false;
        if (! $image || $image[2] !== IMAGETYPE_PNG || $image[0] > 4096 || $image[1] > 4096) {
            throw ValidationException::withMessages(['signature' => 'Envie uma assinatura PNG válida, com dimensões de até 4096 pixels.']);
        }
        $attributes = [
            'company_id' => $receipt->company_id, 'hr_receipt_id' => $receipt->id,
            'signed_by_name' => trim($validated['signed_by_name']), 'signature' => $validated['signature'],
            'signed_at' => now(), 'recorded_by' => $actor?->id,
            'document_hash' => hash('sha256', json_encode(['id' => $receipt->id, 'snapshot' => $receipt->snapshot, 'fingerprint' => $receipt->fingerprint], JSON_THROW_ON_ERROR)),
            'metadata' => ['ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'channel' => $link ? 'public_signature_link' : 'hr_panel', 'signature_link_id' => $link?->id],
        ];
        // A validated public capability must not inherit the browser's unrelated logged-in account.
        $signature = $link
            ? HrReceiptSignature::withoutEvents(fn () => HrReceiptSignature::create($attributes))
            : HrReceiptSignature::create($attributes);
        $link?->update(['used_at' => $signature->signed_at]);
        HrReceiptSignatureLink::where('company_id', $receipt->company_id)->where('hr_receipt_id', $receipt->id)->whereNull('used_at')->whereNull('revoked_at')->update(['revoked_at' => $signature->signed_at]);
        app(AuditRecorder::class)->recordModelEvent($signature, 'hr.receipt.signed', 'Recibo de RH #'.$receipt->id.($link ? ' assinado via link público' : ' assinado em tela'), null,
            ['signed_by_name' => $signature->signed_by_name, 'signed_at' => $signature->signed_at->toIso8601String(), 'document_hash' => $signature->document_hash, 'signature_hash' => hash('sha256', $signature->signature)],
            $actor?->id, $link ? AuditSource::PUBLIC : AuditSource::WEB, $attributes['metadata']);

        return $signature;
    }
}
