<?php

namespace App\Console\Commands;

use App\Enum;
use App\Models\User;
use App\Services\Partner\Actions\CreatePartner;
use App\Services\Partner\PartnerService;
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
        // $data = [
        //     'name'                  => '',
        //     'document_type'         => 'abc',
        //     'document_number'       => '070.934.799-52',
        //     'state_tax_id'          => 'null',
        //     'state_tax_indicator'   => '1',
        //     'municipal_tax_id'      => null,
        //     'created_by'            => 1,
        //     'updated_by'            => 5,
        // ];


        // $action = new CreatePartner();
        // $result = $action->execute($data);

        // ds($action->getMessage())->label('Message');
        // ds($action->getErrors())->label('Erros');


        $service = new PartnerService();
        $partner = $service->getPartnerById(500);

        ds($service->hasError())->label('possui erro?');
        ds($service->getMessageUser());
        if($service->hasError()){
            ds($service->getMessageUser());
            $this->halt();
        }

    }
}
