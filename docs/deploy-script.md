# Script de Deploy

O projeto agora possui um script em [deploy/deploy.sh](/c:/Users/elisa/Documents/tornedon/deploy/deploy.sh) para padronizar atualizacoes da aplicacao.

## Uso rapido

No servidor:

```bash
cd /home/dionathan/tornedon
chmod +x deploy/deploy.sh
./deploy/deploy.sh
```

Esse fluxo executa:

1. `git pull origin main`
2. valida a conexao com o banco antes de migrar
3. `artisan down`
4. `composer install --no-dev --optimize-autoloader`
5. `artisan optimize:clear`
6. `artisan migrate --force`
7. `artisan config:cache`
8. `artisan route:cache`
9. `artisan view:cache`
10. `artisan optimize`
11. `artisan horizon:terminate`
12. `artisan up`

Antes e depois do ciclo de cache/deploy, o script tambem normaliza permissoes em `storage` e `bootstrap/cache` para evitar que esses caminhos fiquem presos ao usuario que rodou o ultimo comando no servidor.

## Variaveis opcionais

Voce pode personalizar o comportamento sem editar o script:

```bash
BUILD_FRONTEND=1 ./deploy/deploy.sh
```

### Variaveis suportadas

- `PHP_BIN`: binario do PHP. Padrao `php`
- `COMPOSER_BIN`: binario do Composer. Padrao `composer`
- `NPM_BIN`: binario do npm. Padrao `npm`
- `GIT_BIN`: binario do Git. Padrao `git`
- `APP_ENVIRONMENT`: ambiente. Padrao `production`
- `APP_BRANCH`: branch usada no pull. Padrao `main`
- `MAINTENANCE_MODE`: `1` ou `0`. Padrao `1`
- `BUILD_FRONTEND`: `1` ou `0`. Padrao `0`
- `RUN_MIGRATIONS`: `1` ou `0`. Padrao `1`
- `RESTART_QUEUES`: `1` ou `0`. Padrao `1`
- `RELOAD_SUPERVISOR`: `1` ou `0`. Padrao `0`
- `SUPERVISORCTL_BIN`: comando usado para chamar o supervisor. Padrao `sudo supervisorctl`
- `SUPERVISOR_PROGRAMS`: programas do supervisor a reiniciar. Padrao `tornedon-horizon tornedon-schedule`
- `FIX_PERMISSIONS`: `1` ou `0`. Padrao `1`
- `WEB_USER`: usuario do PHP-FPM/web server. Padrao `www-data`
- `WEB_GROUP`: grupo compartilhado entre deploy e web server. Padrao `www-data`
- `DEPLOY_USER`: usuario que deve manter acesso apos o deploy. Padrao usuario atual
- `WRITABLE_PATHS`: caminhos gravaveis normalizados. Padrao `storage bootstrap/cache`
- `DEPLOY_UMASK`: umask aplicada durante o deploy. Padrao `0002`
- `DB_CONNECT_TIMEOUT`: timeout da conexao PDO MySQL/MariaDB em segundos. Padrao `10`

## Exemplos

Deploy com build frontend:

```bash
BUILD_FRONTEND=1 ./deploy/deploy.sh
```

Deploy sem maintenance mode:

```bash
MAINTENANCE_MODE=0 ./deploy/deploy.sh
```

Deploy recarregando o supervisor:

```bash
RELOAD_SUPERVISOR=1 ./deploy/deploy.sh
```

Se o `horizon:terminate` exibir `Failed to kill process ... (Operation not permitted)`, normalmente o Horizon foi iniciado por outro usuario no `supervisor`. Nesse caso, prefira:

```bash
RELOAD_SUPERVISOR=1 ./deploy/deploy.sh
```

Assim o script tenta o encerramento gracioso e, se houver erro de permissao ao matar os workers, faz fallback para `supervisorctl restart` nos programas configurados em `SUPERVISOR_PROGRAMS`.

Deploy com programas customizados no supervisor:

```bash
RELOAD_SUPERVISOR=1 SUPERVISOR_PROGRAMS="tornedon-horizon tornedon-schedule" ./deploy/deploy.sh
```

Deploy com grupo compartilhado e normalizacao explicita de permissoes:

```bash
WEB_USER=www-data WEB_GROUP=www-data DEPLOY_USER=dionathan ./deploy/deploy.sh
```

## Permissoes recomendadas no servidor

Para impedir que `storage/logs`, `storage/framework` e `bootstrap/cache` quebrem quando o deploy for executado por um usuario e o PHP rodar por outro, mantenha um grupo compartilhado e ACL/default ACL nesses caminhos.

Exemplo no Ubuntu/Debian:

```bash
sudo usermod -a -G www-data dionathan
sudo chgrp -R www-data /home/dionathan/tornedon/storage /home/dionathan/tornedon/bootstrap/cache
sudo chmod -R ug+rwX /home/dionathan/tornedon/storage /home/dionathan/tornedon/bootstrap/cache
sudo find /home/dionathan/tornedon/storage /home/dionathan/tornedon/bootstrap/cache -type d -exec chmod g+s {} +
sudo setfacl -R -m u:dionathan:rwx -m u:www-data:rwx -m g:www-data:rwx /home/dionathan/tornedon/storage /home/dionathan/tornedon/bootstrap/cache
sudo setfacl -R -d -m u:dionathan:rwx -m u:www-data:rwx -m g:www-data:rwx /home/dionathan/tornedon/storage /home/dionathan/tornedon/bootstrap/cache
```

Se `setfacl` nao estiver instalado:

```bash
sudo apt install -y acl
```

Com isso, arquivos novos herdarao o grupo correto e tanto o usuario de deploy quanto o `www-data` continuarao conseguindo escrever logs, cache compilado e views compiladas sem se bloquearem mutuamente.

## Fluxo recomendado no servidor

Depois de atualizar o codigo com `git pull`, rode:

```bash
cd /home/dionathan/tornedon
./deploy/deploy.sh
```

Se quiser que o proprio script atualize o codigo de outra branch:

```bash
cd /home/dionathan/tornedon
APP_BRANCH=staging ./deploy/deploy.sh
```

Se tambem atualizar assets frontend:

```bash
cd /home/dionathan/tornedon
BUILD_FRONTEND=1 ./deploy/deploy.sh
```

## Falha de timeout no banco

Se o deploy falhar com `SQLSTATE[HY000] [2002] Connection timed out`, o problema costuma estar fora do codigo:

- `DB_HOST`/`DB_PORT` incorretos no `.env` do servidor
- firewall ou security group bloqueando a porta `3306`
- MySQL/MariaDB remoto fora do ar ou sem bind externo

O script agora valida a conexao antes de colocar a aplicacao em manutencao. Se precisar, ajuste o tempo de espera:

```bash
DB_CONNECT_TIMEOUT=15 ./deploy/deploy.sh
```
