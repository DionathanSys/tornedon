<?php

namespace App\Console\Commands;

use App\Enum;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CommandTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:command-test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $var = [
            'name'                  => 'required|string|max:255',
            'type'                  => 'required|array|min:1',
            'type.*'                => 'required|string|in:'.implode(',', array_map(fn($case) => $case->value, Enum\Partner\Type::cases())),
            'document_type'         => 'required|string|in:cnpj,cpf',
            'document_number'       => 'required|string|min:14|max:18',
            'is_active'             => 'required|boolean',
            'state_tax_id'          => 'nullable|string|max:50',
            'state_tax_indicator'   => 'nullable|string|max:50',
            'municipal_tax_id'      => 'nullable|string|max:50',
            'created_by'            => 'required|integer|exists:users,id',
            'updated_by'            => 'required|integer|exists:users,id',
        ];

        ds($var);
    }
}
