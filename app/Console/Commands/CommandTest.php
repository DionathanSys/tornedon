<?php

namespace App\Console\Commands;

use App\Enum;
use App\Models\Address;
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
         $companyPartner = CompanyPartner::where('company_id', 3)
            ->where('partner_id', 2)
            ->first();
            
            return $companyPartner->addresses()->first();
        return $companyPartner ? $companyPartner->addresses()->first() : null;
   }
}
