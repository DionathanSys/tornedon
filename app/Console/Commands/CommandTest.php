<?php

namespace App\Console\Commands;

use App\Enum;
use App\Models\CompanyPartner;
use App\Models\Partner;
use App\Models\User;
use App\Services\Partner\Actions\CreatePartner;
use App\Services\Partner\PartnerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

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
      $companyPartner = CompanyPartner::with('addresses', 'contacts', 'partner')->first();

      $this->info('Company Partner:' . $companyPartner->id);
      $this->info('Company:' . $companyPartner->company->name . ', ID: ' . $companyPartner->company->id);
      $this->info('Partner:' . $companyPartner->partner->name . ', ID: ' . $companyPartner->partner->id);
      foreach ($companyPartner->addresses as $address) {
         $this->info('Address:' . $address->street . ', ID: ' . $address->id);
      }
      foreach ($companyPartner->contacts as $contact) {
         $this->info('Contact:' . $contact->name . ', ID: ' . $contact->id);
      }


   }
}
