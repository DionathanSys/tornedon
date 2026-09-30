# Backup do Banco de Dados

O projeto agora possui uma rotina de backup integrada ao scheduler do Laravel.

## O que foi configurado

- Comando Artisan: `php artisan backup:database`
- Agendamento diário: `02:00`
- Diretório temporário padrão: `storage/app/backups/database`
- Retenção padrão: `7` dias
- Compressão padrão: habilitada (`.sql.gz`)
- O armazenamento remoto padrão é o disco `r2` configurado no Laravel.

## Execução manual

Para validar a configuração sem gerar arquivo:

```bash
php artisan backup:database --dry-run
```

Para gerar um backup imediatamente:

```bash
php artisan backup:database
```

## Configurações opcionais no `.env`

```dotenv
BACKUP_DB_ENABLED=true
BACKUP_DB_CONNECTION=mysql
BACKUP_DB_BINARY=/usr/bin/mysqldump
BACKUP_DB_DISK=r2
BACKUP_DB_PATH=backups/database
BACKUP_DB_DIRECTORY=app/backups/database
BACKUP_DB_FILE_PREFIX=database_backup
BACKUP_DB_COMPRESS=true
BACKUP_DB_KEEP_DAYS=7
BACKUP_DB_SCHEDULE_AT=02:00
BACKUP_DB_TIMEOUT=600
BACKUP_DB_ALERT_EMAIL=administrador@example.com
# BACKUP_DB_ALERT_MAILER=resend
```

Notas:

- Se `BACKUP_DB_BINARY` não for informado, a aplicação tenta localizar `mysqldump`/`mariadb-dump` automaticamente.
- Em ambiente Laragon no Windows, o auto-detect cobre o caminho padrão do MySQL instalado pelo Laragon.
- `BACKUP_DB_DISK=r2` envia o arquivo ao disco R2 configurado em `config/filesystems.php` e remove o arquivo temporário local após confirmar o upload.
- Se `BACKUP_DB_DISK` ficar vazio, o backup permanece no diretório local configurado em `BACKUP_DB_DIRECTORY`.
- O usuário do banco precisa ter permissões para leitura, views, triggers, rotinas e eventos.

## Ativando a rotina no Windows

O agendamento do Laravel só executa se o scheduler estiver registrado no Windows.

Para criar a tarefa agendada:

```powershell
powershell -ExecutionPolicy Bypass -File .\deploy\install-laravel-scheduler.ps1
```

Essa tarefa executa `php artisan schedule:run` a cada minuto, permitindo que o backup diário rode no horário configurado.

## Ativando a rotina no Linux com Supervisor

O deploy já espera um programa chamado `tornedon-schedule`. Instale o exemplo no Supervisor, ajustando o caminho do projeto:

```bash
sudo cp deploy/supervisor/tornedon-schedule.conf.example /etc/supervisor/conf.d/tornedon-schedule.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart tornedon-schedule
```

O processo executa `php artisan schedule:work` continuamente. Valide a configuração e execute um backup manual antes de aguardar o horário automático:

```bash
php artisan backup:database --dry-run
php artisan backup:database
```

Para o R2, mantenha o bucket privado e configure `R2_*` no ambiente de produção. O prefixo `BACKUP_DB_PATH` deve ser reservado para os backups do banco.
