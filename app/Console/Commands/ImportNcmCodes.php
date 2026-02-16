<?php

namespace App\Console\Commands;

use App\Models\NcmCode;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log as FacadesLog;
use Opcodes\LogViewer\Logs\Log;

class ImportNcmCodes extends Command
{
    protected $signature = 'ncm:import';

    protected $description = 'Importa os códigos NCM da API do Siscomex (Portal Único)';

    private const API_URL = 'https://portalunico.siscomex.gov.br/classif/api/publico/nomenclatura/download/json';

    public function handle(): int
    {
        $this->info('Baixando dados do Siscomex...');

        $response = Http::withoutVerifying()
            ->timeout(120)
            ->get(self::API_URL);

        if ($response->failed()) {
            $this->error('Falha ao baixar dados. HTTP: ' . $response->status());
            return self::FAILURE;
        }

        $data = $response->json();
        $nomenclaturas = $data['Nomenclaturas'] ?? [];

        if (empty($nomenclaturas)) {
            $this->error('Nenhuma nomenclatura encontrada no JSON.');
            return self::FAILURE;
        }

        $this->info("Encontrados " . count($nomenclaturas) . " registros.");
        $this->info('Importando...');

        $bar = $this->output->createProgressBar(count($nomenclaturas));
        $bar->start();

        // Truncate fora da transaction (DDL causa commit implícito no MySQL)
        NcmCode::truncate();

        $chunks = array_chunk($nomenclaturas, 500);

        foreach ($chunks as $chunk) {
            DB::transaction(function () use ($chunk, $bar) {
                $records = [];
                
                FacadesLog::debug('Processando chunk de NCM', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'chunk_size' => count($chunk),
                    'first_item' => $chunk[0] ?? null,
                    'second_item' => $chunk[1] ?? null,
                ]);

                foreach ($chunk as $item) {
                    $code = str_replace('.', '', $item['Codigo'] ?? '');
                    $endDate = ($item['Data_Fim'] ?? '') === '31/12/9999'
                        ? null
                        : $this->parseDate($item['Data_Fim'] ?? null);

                    $records[] = [
                        'code' => $code,
                        'description' => $item['Descricao'] ?? '',
                        'start_date' => $this->parseDate($item['Data_Inicio'] ?? null),
                        'end_date' => $endDate,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $bar->advance();
                }

                NcmCode::insert($records);
            });
        }

        $bar->finish();
        $this->newLine();
        $this->info('Importação concluída! Total: ' . NcmCode::count() . ' registros.');

        return self::SUCCESS;
    }

    private function parseDate(?string $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/m/Y', $date)->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
