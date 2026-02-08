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

    /**
     * Execute the console command.
     */
    // public function handle()
    // {
    //     $this->info('=== Teste do método associatePartnerCompany ===');
    //     $this->newLine();

    //     $service = new PartnerService();

    //     // Cenário 1: Criar parceiro e associar com sucesso
    //     $this->info('📝 Cenário 1: Criar parceiro e associar com empresa');
    //     $this->line('--------------------------------------------');

    //     $partnerData = [
    //         'name' => 'Parceiro Teste LTDA',
    //         'document_type' => 'cnpj',
    //         'document_number' => '12.345.678/0001-90',
    //         'is_active' => true,
    //         'state_tax_id' => null,
    //         'municipal_tax_id' => '555',
    //         'state_tax_indicator' => 1, // CONTRIBUINTE_ICMS
    //         'created_by' => 1,
    //         'banana' => 'value to be ignored',
    //     ];

    //     $partner = $service->createPartner($partnerData);

    //     if ($partner) {
    //         $this->info("✅ Parceiro criado: ID {$partner->id} - {$partner->name}");
            
    //         // Obter primeira empresa disponível ou criar uma
    //         $company = \App\Models\Company::first();
            
    //         if (!$company) {
    //             $this->warn('⚠️  Nenhuma empresa encontrada no banco.');
    //             return;
    //         }

    //         $this->info("📌 Empresa selecionada: ID {$company->id}");

    //         // Testar associação
    //         $associationData = [
    //             'type' => [Enum\Partner\Type::CUSTOMER->value],
    //             'invoice_threshold' => 10000.00,
    //             'is_active' => true,
    //         ];

    //         $this->newLine();
    //         $this->info('🔗 Associando parceiro com empresa...');
    //         $companyPartner = $service->associatePartnerCompany(
    //             $partner->id,
    //             $company->id,
    //             $associationData
    //         );

    //         if ($companyPartner) {
    //             $this->info("✅ Associação criada com sucesso!");
    //             $this->line("   - ID: {$companyPartner->id}");
    //             $this->line("   - Tipo: " . implode(', ', $companyPartner->type));
    //             $this->line("   - Limite de Fatura: R$ {$companyPartner->invoice_threshold}");
    //             $this->line("   - Ativo: " . ($companyPartner->is_active ? 'Sim' : 'Não'));
    //         } else {
    //             $this->error('❌ Erro ao associar: ' . $service->getMessage());
    //             $this->line('Erros: ' . implode(', ', $service->getErrors()));
    //         }

    //     // Cenário 2: Tentar associar novamente (deve retornar existente)
    //         $this->newLine(2);
    //         $this->info('📝 Cenário 2: Tentar associar o mesmo parceiro novamente');
    //         $this->line('--------------------------------------------');

    //         $companyPartner2 = $service->associatePartnerCompany(
    //             $partner->id,
    //             $company->id,
    //             $associationData
    //         );

    //         if ($companyPartner2) {
    //             $this->info("✅ Retornou associação existente!");
    //             $this->line("   - ID: {$companyPartner2->id} (mesmo ID anterior)");
    //         }

    //     // Cenário 3: Testar com múltiplos tipos
    //         $this->newLine(2);
    //         $this->info('📝 Cenário 3: Criar novo parceiro com múltiplos tipos');
    //         $this->line('--------------------------------------------');

    //         $partnerData2 = [
    //             'name' => 'Parceiro Multi-Tipo SA',
    //             'document_type' => 'cnpj',
    //             'document_number' => '98.765.432/0001-99',
    //             'is_active' => true,
    //             'state_tax_indicator' => 1, // CONTRIBUINTE_ICMS
    //             'created_by' => 1,
    //         ];

    //         $partner2 = $service->createPartner($partnerData2);

    //         if ($partner2) {
    //             $this->info("✅ Segundo parceiro criado: ID {$partner2->id}");

    //             $multiTypeData = [
    //                 'type' => [
    //                     Enum\Partner\Type::CUSTOMER->value,
    //                     Enum\Partner\Type::SUPPLIER->value
    //                 ],
    //                 'invoice_threshold' => 25000.00,
    //                 'is_active' => true,
    //             ];

    //             $companyPartner3 = $service->associatePartnerCompany(
    //                 $partner2->id,
    //                 $company->id,
    //                 $multiTypeData
    //             );

    //             if ($companyPartner3) {
    //                 $this->info("✅ Associação com múltiplos tipos criada!");
    //                 $this->line("   - Tipos: " . implode(', ', $companyPartner3->type));
    //             }
    //         }

    //     // Cenário 4: Testar validação - tipo vazio
    //         $this->newLine(2);
    //         $this->info('📝 Cenário 4: Testar validação - tipo vazio (deve falhar)');
    //         $this->line('--------------------------------------------');

    //         $invalidData = [
    //             'type' => [],
    //             'invoice_threshold' => 5000.00,
    //             'is_active' => true,
    //         ];

    //         $companyPartner4 = $service->associatePartnerCompany(
    //             $partner->id,
    //             $company->id,
    //             $invalidData
    //         );

    //         if (!$companyPartner4) {
    //             $this->info("✅ Validação funcionou corretamente!");
    //             $this->line("   - Erro: " . $service->getMessageUser());
    //             $this->line("   - Detalhes: " . implode(', ', $service->getErrors()));
    //         } else {
    //             $this->error("❌ Deveria ter falhado mas passou!");
    //         }

    //     // Cenário 5: Testar validação - tipo inválido
    //         $this->newLine(2);
    //         $this->info('📝 Cenário 5: Testar validação - tipo inválido (deve falhar)');
    //         $this->line('--------------------------------------------');

    //         $invalidData2 = [
    //             'type' => ['tipo_invalido'],
    //             'invoice_threshold' => 5000.00,
    //             'is_active' => true,
    //         ];

    //         $companyPartner5 = $service->associatePartnerCompany(
    //             $partner->id,
    //             $company->id,
    //             $invalidData2
    //         );

    //         if (!$companyPartner5) {
    //             $this->info("✅ Validação de tipo inválido funcionou!");
    //             $this->line("   - Erro: " . $service->getMessage());
    //         } else {
    //             $this->error("❌ Deveria ter rejeitado tipo inválido!");
    //         }

    //     // Cenário 6: Testar invoice_threshold negativo
    //         $this->newLine(2);
    //         $this->info('📝 Cenário 6: Testar invoice_threshold negativo (deve falhar)');
    //         $this->line('--------------------------------------------');

    //         $invalidData3 = [
    //             'type' => [Enum\Partner\Type::CUSTOMER->value],
    //             'invoice_threshold' => -1000.00,
    //             'is_active' => true,
    //         ];

    //         $companyPartner6 = $service->associatePartnerCompany(
    //             $partner->id,
    //             $company->id,
    //             $invalidData3
    //         );

    //         if (!$companyPartner6) {
    //             $this->info("✅ Validação de valor negativo funcionou!");
    //             $this->line("   - Erro: " . $service->getMessage());
    //         } else {
    //             $this->error("❌ Deveria ter rejeitado valor negativo!");
    //         }

    //         $this->newLine(2);
    //         $this->info('=== Testes concluídos! ===');

    //         // Limpeza: excluir registros criados durante o teste
    //         $this->newLine();
    //         $this->info('🧹 Limpando registros de teste...');

    //         try {
    //             // Excluir associações
    //             $deletedAssociations = CompanyPartner::whereIn('partner_id', [$partner->id, $partner2->id])->delete();
    //             $this->line("   - {$deletedAssociations} associação(ões) excluída(s)");

    //             // Excluir parceiros
    //             $partner->forceDelete();
    //             $partner2->forceDelete();
    //             $this->line("   - 2 parceiro(s) excluído(s)");

    //             $this->info('✅ Limpeza concluída com sucesso!');
    //         } catch (\Exception $e) {
    //             $this->error('❌ Erro ao limpar registros: ' . $e->getMessage());
    //         }

    //     } else {
    //         $this->error('❌ Erro ao criar parceiro: ' . $service->getMessage());
    //         $errors = $service->getErrors();
    //         if (is_array($errors)) {
    //             foreach ($errors as $field => $messages) {
    //                 if (is_array($messages)) {
    //                     $this->line('  - ' . implode(', ', $messages));
    //                 } else {
    //                     $this->line('  - ' . $messages);
    //                 }
    //             }
    //         }
    //     }
    // }

    public function handle()
    {
       dd   (Storage::disk('public')->exists('data/ncm.json') );
    }

    }
