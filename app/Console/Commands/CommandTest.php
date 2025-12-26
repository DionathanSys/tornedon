<?php

namespace App\Console\Commands;

use App\Enum;
use Illuminate\Console\Command;

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
        $var = 'required|string|in:'.implode(',', Enum\Partner\Type::toSelectArray());

        dd($var);
    }
}
