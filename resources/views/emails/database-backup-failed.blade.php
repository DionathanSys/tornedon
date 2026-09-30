Falha no backup automático do banco de dados.

Conexão: {{ $connectionName }}
Horário: {{ $failedAt }}
Mensagem: {{ $failureMessage }}

Verifique os logs da aplicação e execute o comando manualmente após corrigir a causa:
php artisan backup:database
