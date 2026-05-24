<?php

namespace App\Console\Commands;

use App\Enum\Product\Origin;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Enum\Tax\StateTaxIndicator;
use App\Models\Address;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Contact;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductTax;
use App\Models\Service;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedTestDataCommand extends Command
{
    protected $signature = 'app:seed-test-data
        {--company=Empresa Teste : Nome da empresa que recebera os dados}
        {--email=test@example.com : E-mail do usuario principal}
        {--password=password : Senha do usuario principal}
        {--products=5 : Quantidade de produtos de teste}
        {--services=3 : Quantidade de servicos de teste}
        {--partners=4 : Quantidade de parceiros de teste}';

    protected $description = 'Cria dados de teste basicos vinculados a uma empresa';

    public function handle(): int
    {
        $companyName = trim((string) $this->option('company'));
        $email = trim((string) $this->option('email'));
        $password = (string) $this->option('password');
        $productCount = max(1, (int) $this->option('products'));
        $serviceCount = max(1, (int) $this->option('services'));
        $partnerCount = max(1, (int) $this->option('partners'));

        if ($companyName === '' || $email === '') {
            $this->error('Informe valores validos para --company e --email.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($companyName, $email, $password, $productCount, $serviceCount, $partnerCount): void {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => 'Usuario Teste',
                    'password' => $password,
                    'email_verified_at' => now(),
                ],
            );

            $company = Company::query()->firstOrCreate(
                ['name' => $companyName],
                [
                    'document_number' => null,
                    'address' => [
                        'street' => 'Rua de Teste',
                        'number' => '100',
                        'neighborhood' => 'Centro',
                        'city' => 'Sao Paulo',
                        'state' => 'SP',
                        'zip' => '01000-000',
                    ],
                    'phone' => '(11) 99999-0000',
                    'email' => $email,
                    'is_active' => true,
                    'created_by' => $user->id,
                ],
            );

            if (blank($company->document_number)) {
                $company->forceFill([
                    'document_number' => $this->makeDocumentNumber($company->id, 0),
                    'updated_by' => $user->id,
                ])->save();
            }

            DB::table('company_user')->updateOrInsert(
                ['company_id' => $company->id, 'user_id' => $user->id],
                [
                    'role' => 'owner',
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $categories = collect([
                ['name' => 'Produtos de Teste', 'description' => 'Categoria padrao para produtos de teste'],
                ['name' => 'Materiais de Teste', 'description' => 'Categoria auxiliar para estoque de teste'],
            ])->mapWithKeys(function (array $data) use ($company, $user): array {
                $category = Category::query()->firstOrCreate(
                    ['company_id' => $company->id, 'name' => $data['name']],
                    [
                        'description' => $data['description'],
                        'is_active' => true,
                        'created_by' => $user->id,
                    ],
                );

                return [$category->name => $category];
            });

            for ($index = 1; $index <= $partnerCount; $index++) {
                $partner = Partner::query()->firstOrCreate(
                    ['document_number' => $this->makeDocumentNumber($company->id, 100 + $index)],
                    [
                        'name' => sprintf('Parceiro Teste %02d - %s', $index, $company->name),
                        'document_type' => 'CNPJ',
                        'state_tax_id' => str_pad((string) ($company->id * 100 + $index), 9, '0', STR_PAD_LEFT),
                        'state_tax_indicator' => StateTaxIndicator::CONTRIBUINTE_ICMS->value,
                        'municipal_tax_id' => str_pad((string) ($company->id * 10 + $index), 8, '0', STR_PAD_LEFT),
                        'created_by' => $user->id,
                    ],
                );

                $companyPartner = CompanyPartner::query()->firstOrCreate(
                    ['company_id' => $company->id, 'partner_id' => $partner->id],
                    [
                        'type' => $index % 2 === 0 ? ['supplier'] : ['customer'],
                        'invoice_threshold' => 0,
                        'is_active' => true,
                    ],
                );

                Address::query()->updateOrCreate(
                    ['company_partner_id' => $companyPartner->id],
                    [
                        'street' => 'Rua Parceiro ' . $index,
                        'number' => (string) (100 + $index),
                        'neighborhood' => 'Centro',
                        'city' => 'Sao Paulo',
                        'state' => 'SP',
                        'country' => 'Brasil',
                        'postal_code' => '01001-000',
                        'city_code' => '3550308',
                        'updated_by' => $user->id,
                        'created_by' => $user->id,
                    ],
                );

                Contact::query()->updateOrCreate(
                    [
                        'company_partner_id' => $companyPartner->id,
                        'email' => sprintf('parceiro%02d+empresa%d@example.com', $index, $company->id),
                    ],
                    [
                        'phone' => '(11) 3333-000' . $index,
                        'mobile' => '(11) 99999-000' . $index,
                        'notify' => true,
                        'is_active' => true,
                        'updated_by' => $user->id,
                        'created_by' => $user->id,
                    ],
                );
            }

            for ($index = 1; $index <= $productCount; $index++) {
                $product = Product::query()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'product_code' => sprintf('TESTE-%d-%03d', $company->id, $index),
                    ],
                    [
                        'name' => sprintf('Produto Teste %02d', $index),
                        'description' => 'Produto gerado automaticamente para testes internos.',
                        'category_id' => $categories['Produtos de Teste']->id,
                        'is_active' => true,
                        'has_st' => false,
                        'has_stock_control' => true,
                        'unit' => Unit::UN->value,
                        'profit_margin' => 25,
                        'min_sale_price' => 10,
                        'origin_sale_price' => OriginSalePrice::FREE->value,
                        'sale_price_value' => 25 + $index,
                        'created_by' => $user->id,
                        'updated_by' => $user->id,
                    ],
                );

                ProductTax::query()->updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'product_origin' => Origin::NACIONAL->value,
                        'ncm_code' => '84715010',
                        'cest_code' => null,
                        'icms' => null,
                        'ipi' => null,
                        'pis' => null,
                        'cofins' => null,
                        'created_by' => $user->id,
                        'updated_by' => $user->id,
                    ],
                );

                ProductStock::query()->updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'company_id' => $company->id,
                        'quantity_total' => 10 * $index,
                        'quantity_reserved' => 0,
                        'quantity_minimum' => 1,
                        'quantity_maximum' => 100,
                        'average_cost' => 10 + $index,
                        'last_cost' => 9 + $index,
                        'last_sale_price' => 25 + $index,
                        'last_movement_date' => now()->toDateString(),
                        'last_movement_type' => 'entry',
                        'is_active' => true,
                        'allow_negative' => false,
                        'additional_info' => ['source' => 'app:seed-test-data'],
                        'created_by' => $user->id,
                        'updated_by' => $user->id,
                    ],
                );
            }

            for ($index = 1; $index <= $serviceCount; $index++) {
                Service::query()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'service_code' => sprintf('SERV-%d-%03d', $company->id, $index),
                    ],
                    [
                        'name' => sprintf('Servico Teste %02d', $index),
                        'description' => 'Servico gerado automaticamente para testes internos.',
                        'price' => 150 + ($index * 10),
                        'min_sale_price' => 100,
                        'accept_customer_discount' => true,
                        'cost' => 70 + ($index * 5),
                        'category' => 'Servicos de Teste',
                        'is_active' => true,
                        'requires_approval' => false,
                        'tax_classification' => '1401',
                        'tax_rate' => 5,
                        'municipal_tax_code' => '1401',
                        'created_by' => $user->id,
                        'updated_by' => $user->id,
                    ],
                );
            }

            $this->line("Empresa: {$company->name} (#{$company->id})");
            $this->line("Usuario: {$user->email} (#{$user->id})");
            $this->info("Dados de teste prontos: {$partnerCount} parceiros, {$productCount} produtos e {$serviceCount} servicos.");
        });

        return self::SUCCESS;
    }

    private function makeDocumentNumber(int $companyId, int $offset): string
    {
        return str_pad((string) ($companyId * 1000 + $offset), 14, '0', STR_PAD_LEFT);
    }
}
