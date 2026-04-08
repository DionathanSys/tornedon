# Backup do Banco de Dados

O projeto agora possui uma rotina de backup integrada ao scheduler do Laravel.

## O que foi configurado

- Comando Artisan: `php artisan backup:database`
- Agendamento diário: `02:00`
- Diretório padrão dos backups: `storage/app/backups/database`
- Retenção padrão: `14` dias
- Compressão padrão: habilitada (`.sql.gz`)

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
BACKUP_DB_BINARY=C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe
BACKUP_DB_DIRECTORY=app/backups/database
BACKUP_DB_COMPRESS=true
BACKUP_DB_KEEP_DAYS=14
BACKUP_DB_SCHEDULE_AT=02:00
BACKUP_DB_TIMEOUT=600
```

Notas:

- Se `BACKUP_DB_BINARY` não for informado, a aplicação tenta localizar `mysqldump`/`mariadb-dump` automaticamente.
- Em ambiente Laragon no Windows, o auto-detect cobre o caminho padrão do MySQL instalado pelo Laragon.

## Ativando a rotina no Windows

O agendamento do Laravel só executa se o scheduler estiver registrado no Windows.

Para criar a tarefa agendada:

```powershell
powershell -ExecutionPolicy Bypass -File .\deploy\install-laravel-scheduler.ps1
```

Essa tarefa executa `php artisan schedule:run` a cada minuto, permitindo que o backup diário rode no horário configurado.
