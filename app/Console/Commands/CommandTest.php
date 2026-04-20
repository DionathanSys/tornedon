<?php

namespace App\Console\Commands;

use App\Enum;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Models\Address;
use App\Models\CompanyPartner;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItemOrigin;
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
      $record = FiscalDocument::find(72);

      $origin = FiscalDocumentItemOrigin::query()
                ->where('origin_fiscal_document_id', $record->id)
                ->exists();

      $visible = $record->isNfe()
            && $record->operation_type === OperationType::ENTRADA
            && $record->status !== Status::CANCELLED
            && ! $record->canceled
            && ! $origin;

      Log::debug('GeneratePurchaseReturnAction: verificando visibilidade', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'isNfe' => $record->isNfe(),
            'operation_type' => $record->operation_type,
            'status' => $record->status,
            'canceled' => $record->canceled,
            'origin_fiscal_document_id' => $origin,
            'visible' => $visible,
        ]);

   }
}
