<?php

namespace App\Console\Commands;

use App\Enum;
use App\Models\User;
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
        

        ds(implode(',', array_map(fn($case) => $case->value, Enum\Partner\Type::cases())));
    }

}
