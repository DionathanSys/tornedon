<?php

namespace App\Console\Commands;

use App\Services\Commitments\Pdf\ChapecoCommitmentParser;
use App\Services\Commitments\Pdf\ChapecoPaymentReportParser;
use App\Services\Commitments\Pdf\PdfReadingException;
use App\Services\Commitments\Pdf\PdfTextExtractor;
use Illuminate\Console\Command;

class DiagnoseCommitmentPdfCommand extends Command
{
    protected $signature = 'commitments:diagnose-pdf
        {type : commitments ou payments}
        {files* : Caminhos dos PDFs}
        {--issuer-document= : CNPJ esperado do cliente emissor}
        {--creditor-document= : CNPJ esperado da empresa credora (empenhos)}
        {--creditor-name= : Nome esperado da empresa credora (relatórios)}
        {--json : Exibir dados estruturados com valores em centavos}';

    protected $description = 'Diagnostica PDFs de empenhos e pagamentos de Chapecó, sem gravar dados ou executar operações financeiras.';

    public function handle(PdfTextExtractor $extractor, ChapecoCommitmentParser $commitments, ChapecoPaymentReportParser $payments): int
    {
        $type = $this->argument('type');
        if (! in_array($type, ['commitments', 'payments'], true)) {
            $this->error('Tipo inválido. Utilize commitments ou payments.');

            return self::INVALID;
        }
        if (($type === 'payments' && $this->option('creditor-document')) || ($type === 'commitments' && $this->option('creditor-name'))) {
            $this->error('Use --creditor-document para empenhos e --creditor-name para relatórios.');

            return self::INVALID;
        }

        $results = [];
        $failed = false;
        foreach ($this->argument('files') as $file) {
            try {
                $pdf = $extractor->extract($file);
                $data = $type === 'commitments'
                    ? $commitments->parse($pdf, $this->option('issuer-document'), $this->option('creditor-document'))
                    : $payments->parse($pdf, $this->option('issuer-document'), $this->option('creditor-name'));
                $review = $type === 'commitments' ? $data->issues !== [] : $data->requiresReview();
                $results[] = ['file' => $file, 'parser_version' => $type === 'commitments' ? $commitments::VERSION : $payments::VERSION, 'page_count' => count($pdf->pages), 'status' => $review ? 'needs_review' : 'processed', 'data' => $data->toArray()];
                $failed = $failed || $review;
            } catch (PdfReadingException $exception) {
                $failed = true;
                $results[] = ['file' => $file, 'status' => 'failed', 'error' => $exception->getMessage()];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            foreach ($results as $result) {
                $this->info($result['file'].' — '.$result['status']);
                if (isset($result['error'])) {
                    $this->error($result['error']);

                    continue;
                }
                $data = $result['data'];
                if ($type === 'commitments') {
                    $this->table(['Empenho', 'Patrimônio', 'Valor', 'Itens'], [[$data['number'].'/'.$data['fiscal_year'], $data['patrimony'] ?? 'Não identificado', $this->money($data['amount_cents']), count($data['items'])]]);
                } else {
                    $this->line('Páginas: '.$result['page_count'].' | Pagamentos: '.$data['line_count'].' | Declarado: '.($data['declared_total_cents'] === null ? 'Ausente' : $this->money($data['declared_total_cents'])).' | Extraído: '.$this->money($data['extracted_total_cents']));
                    $this->table(['Empenho', 'Pagamento', 'Patrimônio', 'Nota(s)', 'Valor', 'Páginas'], array_map(fn (array $line): array => [$line['commitment_number'].'/'.$line['fiscal_year'], $line['payment_date'], $line['patrimony'] ?? '—', implode(', ', array_column($line['fiscal_documents'], 'number')), $this->money($line['amount_cents']), $line['page_start'].'–'.$line['page_end']], $data['lines']));
                    foreach ($data['lines'] as $line) {
                        foreach ($line['issues'] as $issue) {
                            $this->warn($line['commitment_number'].'/'.$line['fiscal_year'].': '.$issue);
                        }
                    }
                }
                foreach ($data['issues'] as $issue) {
                    $this->warn($issue);
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function money(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
