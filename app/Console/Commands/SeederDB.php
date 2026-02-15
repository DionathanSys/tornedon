<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class SeederDB extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:seeder-db';

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
        if (!Schema::hasTable('companies')) {
            $this->error('Tabela companies não encontrada. Rode as migrations primeiro.');
            return 1;
        }

        if (!Schema::hasTable('users')) {
            $this->error('Tabela users não encontrada. Rode as migrations primeiro.');
            return 1;
        }

        // Criar usuário admin se não existir
        $this->createAdminUser();

        $now = now();

        $companies = [
            [
                'name' => 'Acme Serviços LTDA',
                'address' => json_encode([
                    'street' => 'Rua das Flores',
                    'number' => '123',
                    'city' => 'São Paulo',
                    'state' => 'SP',
                    'zip' => '01000-000'
                ]),
                'phone' => '+55 11 4000-0000',
                'email' => 'contato@acme.com.br',
                'certificate' => null,
                'municipal_tax_id' => null,
                'state_tax_id' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Beta Comércio S/A',
                'address' => json_encode([
                    'street' => 'Avenida Central',
                    'number' => '456',
                    'city' => 'Curitiba',
                    'state' => 'PR',
                    'zip' => '80000-000'
                ]),
                'phone' => '+55 41 3000-1111',
                'email' => 'financeiro@beta.com.br',
                'certificate' => null,
                'municipal_tax_id' => null,
                'state_tax_id' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Gamma Soluções',
                'address' => json_encode([
                    'street' => 'Praça Nova',
                    'number' => '10',
                    'city' => 'Belo Horizonte',
                    'state' => 'MG',
                    'zip' => '30000-000'
                ]),
                'phone' => null,
                'email' => 'contato@gamma.com.br',
                'certificate' => null,
                'municipal_tax_id' => null,
                'state_tax_id' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        try {
            DB::table('companies')->insertOrIgnore($companies);
            $this->info('Companies seeded: ' . count($companies));

            // Vincular companies com users existentes via pivot company_user
            if (!Schema::hasTable('company_user')) {
                $this->warn('Tabela company_user não encontrada; pulando vinculação.');
                return 0;
            }

            $userIds = DB::table('users')->pluck('id')->all();
            if (empty($userIds)) {
                $this->warn('Nenhum user encontrado para vincular.');
                return 0;
            }

            $names = array_column($companies, 'name');
            $companyMap = DB::table('companies')
                ->whereIn('name', $names)
                ->pluck('id', 'name')
                ->toArray();

            $insertRows = [];
            foreach ($companyMap as $name => $companyId) {
                // owner
                $owner = $userIds[array_rand($userIds)];
                $insertRows[] = [
                    'company_id' => $companyId,
                    'user_id' => $owner,
                    'role' => 'owner',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // adicionar um membro extra se houver mais usuários
                if (count($userIds) > 1) {
                    $other = $owner;
                    $tries = 0;
                    while ($other === $owner && $tries < 5) {
                        $other = $userIds[array_rand($userIds)];
                        $tries++;
                    }
                    if ($other !== $owner) {
                        $insertRows[] = [
                            'company_id' => $companyId,
                            'user_id' => $other,
                            'role' => 'member',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }

            try {
                DB::table('company_user')->insertOrIgnore($insertRows);
                $this->info('Vinculação companies->users concluída.');
            } catch (\Exception $e) {
                $this->warn('Erro ao vincular companies com users: ' . $e->getMessage());
            }

            // Criar partners
            $this->createPartners($now);

            // Criar company_partners (vinculação entre company e partner)
            $this->createCompanyPartners($now);

            // Criar endereços para company_partners
            $this->createAddresses($now);

            return 0;
        } catch (\Exception $e) {
            $this->error('Erro ao inserir companies: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Criar partners
     */
    private function createPartners($now): void
    {
        if (!Schema::hasTable('partners')) {
            $this->warn('Tabela partners não encontrada; pulando criação de parceiros.');
            return;
        }

        $partners = [
            [
                'name' => 'Cliente Alpha LTDA',
                'document_number' => '75.813.923/0010-52',
                'document_type' => 'cnpj',
                'state_tax_id' => '123456789',
                'municipal_tax_id' => '987654321',
                'state_tax_indicator' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Fornecedor Beta S/A',
                'document_number' => '04.110.246/0001-77',
                'document_type' => 'cnpj',
                'state_tax_id' => '987654321',
                'municipal_tax_id' => '123456789',
                'state_tax_indicator' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'João Silva',
                'document_number' => '99.451.842/0001-27',
                'document_type' => 'cnpj',
                'state_tax_id' => null,
                'municipal_tax_id' => null,
                'state_tax_indicator' => 9,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        try {
            DB::table('partners')->insertOrIgnore($partners);
            $this->info('Partners criados: ' . count($partners));
        } catch (\Exception $e) {
            $this->error('Erro ao criar partners: ' . $e->getMessage());
        }
    }

    /**
     * Criar company_partners (vinculação entre companies e partners)
     */
    private function createCompanyPartners($now): void
    {
        if (!Schema::hasTable('company_partner')) {
            $this->warn('Tabela company_partner não encontrada; pulando criação de vínculos.');
            return;
        }

        $companies = DB::table('companies')->pluck('id')->all();
        $partners = DB::table('partners')->pluck('id')->all();

        if (empty($companies) || empty($partners)) {
            $this->warn('Não há companies ou partners para vincular.');
            return;
        }

        $companyPartners = [];
        
        // Cada company terá 2 parceiros vinculados
        foreach ($companies as $companyId) {
            foreach (array_slice($partners, 0, 2) as $partnerId) {
                $companyPartners[] = [
                    'company_id' => $companyId,
                    'partner_id' => $partnerId,
                    'type' => json_encode(['customer']),
                    'invoice_threshold' => 0.00,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        try {
            DB::table('company_partner')->insertOrIgnore($companyPartners);
            $this->info('Company-Partners criados: ' . count($companyPartners));
        } catch (\Exception $e) {
            $this->error('Erro ao criar company_partners: ' . $e->getMessage());
        }
    }

    /**
     * Criar endereços para company_partners
     */
    private function createAddresses($now): void
    {
        if (!Schema::hasTable('company_partner')) {
            $this->warn('Tabela company_partner não encontrada; pulando criação de endereços.');
            return;
        }

        if (!Schema::hasTable('addresses')) {
            $this->warn('Tabela addresses não encontrada; pulando criação de endereços.');
            return;
        }

        // Buscar company_partners existentes
        $companyPartners = DB::table('company_partner')->get();
        
        if ($companyPartners->isEmpty()) {
            $this->warn('Nenhum company_partner encontrado para criar endereços.');
            return;
        }

        $addresses = [
            [
                'street' => 'Rua das Palmeiras',
                'number' => '100',
                'complement' => 'Sala 101',
                'neighborhood' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
                'country' => 'Brasil',
                'postal_code' => '01310-100',
                'city_code' => '3550308',
            ],
            [
                'street' => 'Avenida Paulista',
                'number' => '1500',
                'complement' => 'Andar 10',
                'neighborhood' => 'Bela Vista',
                'city' => 'São Paulo',
                'state' => 'SP',
                'country' => 'Brasil',
                'postal_code' => '01310-200',
                'city_code' => '3550308',
            ],
            [
                'street' => 'Rua XV de Novembro',
                'number' => '250',
                'complement' => null,
                'neighborhood' => 'Centro',
                'city' => 'Curitiba',
                'state' => 'PR',
                'country' => 'Brasil',
                'postal_code' => '80020-310',
                'city_code' => '4106902',
            ],
            [
                'street' => 'Rua Marechal Deodoro',
                'number' => '500',
                'complement' => 'Loja 3',
                'neighborhood' => 'Centro',
                'city' => 'Curitiba',
                'state' => 'PR',
                'country' => 'Brasil',
                'postal_code' => '80010-010',
                'city_code' => '4106902',
            ],
            [
                'street' => 'Avenida Afonso Pena',
                'number' => '1000',
                'complement' => 'Conjunto 20',
                'neighborhood' => 'Centro',
                'city' => 'Belo Horizonte',
                'state' => 'MG',
                'country' => 'Brasil',
                'postal_code' => '30130-001',
                'city_code' => '3106200',
            ],
        ];

        try {
            $insertedCount = 0;
            foreach ($companyPartners as $index => $companyPartner) {
                // Cada company_partner recebe 1-2 endereços
                $numAddresses = rand(1, 2);
                
                for ($i = 0; $i < $numAddresses && !empty($addresses); $i++) {
                    $addressData = array_shift($addresses);
                    
                    DB::table('addresses')->insertOrIgnore([
                        'company_partner_id' => $companyPartner->id,
                        'street' => $addressData['street'],
                        'number' => $addressData['number'],
                        'complement' => $addressData['complement'],
                        'neighborhood' => $addressData['neighborhood'],
                        'city' => $addressData['city'],
                        'state' => $addressData['state'],
                        'country' => $addressData['country'],
                        'postal_code' => $addressData['postal_code'],
                        'city_code' => $addressData['city_code'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    
                    $insertedCount++;
                }
                
                if (empty($addresses)) {
                    break;
                }
            }
            
            $this->info("Endereços criados: {$insertedCount}");
        } catch (\Exception $e) {
            $this->warn('Erro ao criar endereços: ' . $e->getMessage());
        }
    }

    /**
     * Criar usuário admin se não existir
     */
    private function createAdminUser(): void
    {
        $adminEmail = 'dev@dev.com';

        // Verificar se admin já existe
        if (User::where('email', $adminEmail)->exists()) {
            $this->info('Usuário admin já existe.');
            return;
        }

        try {
            User::create([
                'name' => 'Administrador',
                'email' => $adminEmail,
                'password' => Hash::make('asd'), // Alterar após primeiro login
            ]);

            $this->info("Usuário admin criado com sucesso!");
            $this->line("Email: {$adminEmail}");
            $this->line("Senha: admin123");
            $this->warn('⚠️  Altere a senha após o primeiro acesso!');
        } catch (\Exception $e) {
            $this->error('Erro ao criar usuário admin: ' . $e->getMessage());
        }
    }
}
