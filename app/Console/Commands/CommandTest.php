<?php

namespace App\Console\Commands;

use App\Enum;
use App\Models\CompanyPartner;
use App\Models\FiscalProfile;
use App\Models\Partner;
use App\Models\User;
use App\Services\Email\Providers\ResendEmailProvider;
use App\Services\Partner\Actions\CreatePartner;
use App\Services\Partner\PartnerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Resend\Laravel\Facades\Resend;

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

   public function handle()
   {
      $data = [
            'partner_id' => 3,
            'company_id' => 3,
            'type' => ['carrier'],
            'invoice_threshold' => 11,
            'is_active' => true,
            'notify_service_order_closed' => false,
            'notify_requisition_closed' => false,
            'notify_fiscal_document_confirmed' => false,
            'email_to_override' => null,
            'email_cc_override' => null,
            'email_bcc_override' => null,
        ];
        Log::debug(__METHOD__ . '@' . __LINE__, [
            'message' => 'Dados preparados para criação de associação',
            'data' => $data,
        ]);
        $companyPartner = CompanyPartner::create($data);
   }
}
