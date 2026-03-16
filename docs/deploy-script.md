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

1. `artisan down`
2. `composer install --no-dev`
3. `artisan optimize:clear`
4. `artisan migrate --force`
5. `artisan config:cache`
6. `artisan route:cache`
7. `artisan view:cache`
8. `artisan optimize`
9. `artisan queue:restart`
10. `artisan up`

## Variaveis opcionais

Voce pode personalizar o comportamento sem editar o script:

```bash
BUILD_FRONTEND=1 ./deploy/deploy.sh
```

### Variaveis suportadas

- `PHP_BIN`: binario do PHP. Padrao `php`
- `COMPOSER_BIN`: binario do Composer. Padrao `composer`
- `NPM_BIN`: binario do npm. Padrao `npm`
- `APP_ENVIRONMENT`: ambiente. Padrao `production`
- `MAINTENANCE_MODE`: `1` ou `0`. Padrao `1`
- `BUILD_FRONTEND`: `1` ou `0`. Padrao `0`
- `RUN_MIGRATIONS`: `1` ou `0`. Padrao `1`
- `RESTART_QUEUES`: `1` ou `0`. Padrao `1`
- `RELOAD_SUPERVISOR`: `1` ou `0`. Padrao `0`
- `SUPERVISORCTL_BIN`: comando usado para chamar o supervisor. Padrao `sudo supervisorctl`
- `SUPERVISOR_PROGRAMS`: programas do supervisor a reiniciar. Padrao `tornedon-queue tornedon-schedule`

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

Deploy com programas customizados no supervisor:

```bash
RELOAD_SUPERVISOR=1 SUPERVISOR_PROGRAMS="tornedon-queue tornedon-schedule" ./deploy/deploy.sh
```

## Fluxo recomendado no servidor

Depois de atualizar o codigo com `git pull`, rode:

```bash
cd /home/dionathan/tornedon
./deploy/deploy.sh
```

Se tambem atualizar assets frontend:

```bash
cd /home/dionathan/tornedon
BUILD_FRONTEND=1 ./deploy/deploy.sh
```
