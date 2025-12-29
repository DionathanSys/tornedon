<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
            DB::table('companies')->insert($companies);
            $this->info('Companies seeded: ' . count($companies));

            // Vincular companies com users existentes via pivot company_user
            if (!Schema::hasTable('company_user')) {
                $this->warn('Tabela company_user não encontrada; pulando vinculação.');
                return 0;
            }

            if (!Schema::hasTable('users')) {
                $this->warn('Tabela users não encontrada; pulando vinculação.');
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

            return 0;
        } catch (\Exception $e) {
            $this->error('Erro ao inserir companies: ' . $e->getMessage());
            return 1;
        }
    }
}
