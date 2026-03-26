<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestResendEmailCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:test-resend {email : O endereço de e-mail de destino para o teste}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa o envio de e-mail através do serviço Resend';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');

        $this->info("Iniciando envio de e-mail de teste para: {$email} utilizando o Resend...");

        try {
            // Forçamos o uso do mailer 'resend' configurado no mail.php
            Mail::mailer('resend')
                ->raw('Este é um e-mail de teste enviado pela aplicação para verificar a integração com o serviço da Resend.', function ($message) use ($email) {
                    $message->to($email)
                            ->subject('Teste de Integração - Resend');
                });

            $this->info('E-mail enviado com sucesso! Verifique a caixa de entrada (ou pasta de spam) de ' . $email);
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Ocorreu um erro ao tentar enviar o e-mail:');
            $this->error($e->getMessage());
            
            return Command::FAILURE;
        }
    }
}
