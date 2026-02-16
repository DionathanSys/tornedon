<?php

namespace App\Console\Commands;

use App\Models\NcmCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearNcmCodes extends Command
{
    protected $signature = 'ncm:clear';

    protected $description = 'Limpa todos os registros da tabela de códigos NCM';

    public function handle(): int
    {
        if (!$this->confirm('Tem certeza que deseja limpar todos os códigos NCM?')) {
            $this->info('Operação cancelada.');
            return self::SUCCESS;
        }

        $count = NcmCode::count();

        NcmCode::truncate();
        Cache::forget('ncm_codes_has_data');

        $this->info("Tabela limpa com sucesso. {$count} registros removidos.");

        return self::SUCCESS;
    }
}
