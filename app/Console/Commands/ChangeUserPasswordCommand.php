<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ChangeUserPasswordCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:change-password
                            {email : Email do usuário}
                            {--password= : Nova senha (se não informada, será solicitada)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Altera a senha de um usuário';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        
        // Procura o usuário pelo email
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("Usuário com email '{$email}' não encontrado.");
            return self::FAILURE;
        }

        // Obtém a nova senha
        $password = $this->option('password');

        if (!$password) {
            $password = $this->secret('Digite a nova senha');
        }

        // Valida a senha
        if (strlen($password) < 8) {
            $this->error('A senha deve ter pelo menos 8 caracteres.');
            return self::FAILURE;
        }

        // Confirma a senha
        $passwordConfirm = $this->secret('Confirme a nova senha');

        if ($password !== $passwordConfirm) {
            $this->error('As senhas não conferem.');
            return self::FAILURE;
        }

        // Atualiza a senha
        $user->update([
            'password' => Hash::make($password),
        ]);

        $this->info("Senha do usuário '{$email}' foi alterada com sucesso!");

        return self::SUCCESS;
    }
}
