<?php

namespace App\Http\Controllers;

use App\Enum\Hr\PaymentNature;
use App\Models\Company;
use App\Models\HrEntry;
use App\Models\HrPerson;
use App\Models\HrReceipt;
use App\Services\Access\UserAccessService;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrService;
use Barryvdh\DomPDF\Facade\Pdf;

class HrDocumentController extends Controller
{
    public function export(Company $company, string $module, HrAccess $access)
    {
        $this->authorizePanel($company);
        abort_unless(in_array($module, ['people', 'entries'], true), 404);
        $permission = $module === 'people' ? 'hr_people' : 'hr_entries';
        $access->authorize(auth()->user(), $company, $permission.'.view');
        $access->authorize(auth()->user(), $company, $permission.'.export');
        $query = $module === 'people' ? HrPerson::query()->with('companyPartner.partner') : HrEntry::query()->with('person.companyPartner.partner');
        $query->where('company_id', $company->id)->orderBy('id');

        return response()->streamDownload(function () use ($query, $company, $module, $access, $permission): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $module === 'people' ? ['Nº', 'Pessoa', 'Vínculos', 'Cargo', 'Valor-base', 'Ativo'] : ['Nº', 'Pessoa', 'Competência', 'Natureza', 'Bruto', 'Descontos', 'Líquido', 'Situação', 'Conta a pagar'], ';', '"', '');
            foreach ($query->lazy() as $record) {
                if (! $access->allows(auth()->user(), $company, $permission.'.export', $record)) {
                    continue;
                }
                $row = $module === 'people'
                    ? [$record->id, $record->companyPartner?->partner?->name, implode(', ', array_map(fn ($role) => ['employee' => 'Funcionário', 'shareholder' => 'Sócio'][$role], $record->roles)), $record->position, $record->base_amount, $record->is_active ? 'Sim' : 'Não']
                    : [$record->id, $record->snapshot['person_name'] ?? $record->person?->companyPartner?->partner?->name, $record->competence_date->format('m/Y'), PaymentNature::options()[$record->nature], $record->gross_amount, $record->deduction_amount, $record->net_amount, $record->status, $record->account_payable_id];
                fputcsv($stream, $row, ';', '"', '');
            }
            fclose($stream);
        }, 'rh-'.$module.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function statement(Company $company, int $entry, HrAccess $access, HrService $hr)
    {
        $this->authorizePanel($company);
        $record = HrEntry::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($entry);
        $access->authorize(auth()->user(), $company, 'hr_entries.view', $record);
        $access->authorize(auth()->user(), $company, 'hr_entries.export', $record);

        return Pdf::loadView('pdf.hr-receipt', ['data' => $hr->documentData($record), 'receipt' => null])->setPaper('a4')->stream('demonstrativo-rh-'.$record->id.'.pdf');
    }

    public function receipt(Company $company, int $receipt, HrAccess $access, HrService $hr)
    {
        $this->authorizePanel($company);
        $record = HrReceipt::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($receipt);
        $entry = HrEntry::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($record->hr_entry_id);
        $access->authorize(auth()->user(), $company, 'hr_entries.view', $entry);
        $access->authorize(auth()->user(), $company, 'hr_receipts.view', $record);
        $access->authorize(auth()->user(), $company, 'hr_receipts.export', $record);
        abort_unless($hr->receiptIsValid($record), 409, 'Recibo invalidado por alteração, estorno ou cancelamento do pagamento.');

        return Pdf::loadView('pdf.hr-receipt', ['data' => $record->snapshot, 'receipt' => $record])->setPaper('a4')->stream('recibo-rh-'.$record->id.'.pdf');
    }

    private function authorizePanel(Company $company): void
    {
        abort_unless(app(UserAccessService::class)->canAccessPanel(auth()->user(), $company, 'admin'), 403);
    }
}
