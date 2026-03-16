# Filas com Redis e Supervisor

Este projeto ja possui jobs Laravel em `app/Jobs`, então o caminho recomendado para producao é:

1. Redis como backend de fila e cache
2. `php artisan queue:work` como worker
3. `supervisor` para manter os workers vivos

## 1. Ajustes no `.env`

Use estes valores como base no servidor:

```env
QUEUE_CONNECTION=redis
QUEUE_AFTER_COMMIT=true

CACHE_STORE=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_QUEUE_CONNECTION=default
REDIS_QUEUE=default
REDIS_QUEUE_RETRY_AFTER=120
REDIS_QUEUE_BLOCK_FOR=5
```

Se o servidor nao tiver a extensao `phpredis`, voce pode usar:

```env
REDIS_CLIENT=predis
```

Nesse caso, instale tambem a dependencia:

```bash
composer require predis/predis
```

Hoje o `.env` local esta com `QUEUE_CONNECTION=async`. Esse valor nao existe em [config/queue.php](/c:/Users/elisa/Documents/tornedon/config/queue.php), entao precisa ser trocado para `redis`.

## 2. Instalar Redis no servidor

Exemplo para Ubuntu/Debian:

```bash
sudo apt update
sudo apt install -y redis-server
sudo systemctl enable redis-server
sudo systemctl start redis-server
redis-cli ping
```

O retorno esperado e `PONG`.

## 3. Limpar cache de configuracao do Laravel

Depois de alterar o `.env`:

```bash
php artisan optimize:clear
php artisan config:cache
```

## 4. Testar a fila manualmente

Antes do `supervisor`, valide o worker manualmente:

```bash
php artisan queue:work redis --queue=default,emails --sleep=1 --tries=3 --timeout=300 --verbose
```

Nesse projeto existe pelo menos uma fila nomeada:

- `emails`: usada por [SendDocumentNotificationJob.php](/c:/Users/elisa/Documents/tornedon/app/Jobs/SendDocumentNotificationJob.php)

Os demais jobs atualmente vao para a fila padrao `default`.

## 5. Configurar o Supervisor

Instalacao no Ubuntu/Debian:

```bash
sudo apt install -y supervisor
sudo systemctl enable supervisor
sudo systemctl start supervisor
```

Crie o arquivo `/etc/supervisor/conf.d/tornedon-worker.conf` com este conteudo:

```ini
[program:tornedon-worker]
process_name=%(program_name)s_%(process_num)02d
directory=/var/www/tornedon
command=/usr/bin/php artisan queue:work redis --queue=default,emails --sleep=1 --tries=3 --timeout=300 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/tornedon/storage/logs/worker.log
stopwaitsecs=3600
```

Se o caminho do PHP ou do projeto for outro, ajuste `command` e `directory`.

## 6. Aplicar a configuracao do Supervisor

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start tornedon-worker:*
sudo supervisorctl status
```

## 7. Deploy seguro quando houver novo codigo

Sempre que publicar alteracoes na aplicacao:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
```

O `queue:restart` faz o worker reiniciar de forma graciosa e o `supervisor` sobe o processo de novo.

## 8. Conferencias rapidas

Verificar jobs falhos:

```bash
php artisan queue:failed
```

Reprocessar jobs falhos:

```bash
php artisan queue:retry all
```

Ver logs:

```bash
tail -f storage/logs/laravel.log
tail -f storage/logs/worker.log
```

## 9. Observacoes para este projeto

- [config/queue.php](/c:/Users/elisa/Documents/tornedon/config/queue.php) agora esta preparado para Redis por padrao.
- [database.php](/c:/Users/elisa/Documents/tornedon/config/database.php) ja possui conexoes Redis configuraveis por `.env`.
- [SendDocumentNotificationJob.php](/c:/Users/elisa/Documents/tornedon/app/Jobs/SendDocumentNotificationJob.php) usa a fila `emails`, entao o worker precisa escutar `default,emails`.
- Se voce quiser separar carga, pode criar dois programas no `supervisor`: um so para `emails` e outro para `default`.
