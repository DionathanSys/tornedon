#!/usr/bin/env bash

set -Eeuo pipefail

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NPM_BIN="${NPM_BIN:-npm}"
GIT_BIN="${GIT_BIN:-git}"
APP_ENVIRONMENT="${APP_ENVIRONMENT:-production}"
APP_BRANCH="${APP_BRANCH:-main}"
MAINTENANCE_MODE="${MAINTENANCE_MODE:-1}"
BUILD_FRONTEND="${BUILD_FRONTEND:-0}"
RUN_MIGRATIONS="${RUN_MIGRATIONS:-1}"
RESTART_QUEUES="${RESTART_QUEUES:-1}"
RELOAD_SUPERVISOR="${RELOAD_SUPERVISOR:-0}"
SUPERVISORCTL_BIN="${SUPERVISORCTL_BIN:-sudo supervisorctl}"
SUPERVISOR_PROGRAMS="${SUPERVISOR_PROGRAMS:-tornedon-horizon tornedon-schedule}"
FIX_PERMISSIONS="${FIX_PERMISSIONS:-1}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"
DEPLOY_USER="${DEPLOY_USER:-$(id -un)}"
WRITABLE_PATHS="${WRITABLE_PATHS:-storage bootstrap/cache}"
SETFACL_BIN="${SETFACL_BIN:-setfacl}"
CHGRP_BIN="${CHGRP_BIN:-chgrp}"
CHMOD_BIN="${CHMOD_BIN:-chmod}"
FIND_BIN="${FIND_BIN:-find}"
DEPLOY_UMASK="${DEPLOY_UMASK:-0002}"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARTISAN="${ROOT_DIR}/artisan"

umask "${DEPLOY_UMASK}"

log() {
    printf '[deploy] %s\n' "$1"
}

run_in_root() {
    (cd "$ROOT_DIR" && "$@")
}

run_supervisorctl() {
    # Split the configured command so values like "sudo supervisorctl" work as expected.
    read -r -a supervisor_cmd <<< "$SUPERVISORCTL_BIN"
    (cd "$ROOT_DIR" && "${supervisor_cmd[@]}" "$@")
}

normalize_permissions() {
    if [[ "${FIX_PERMISSIONS}" != "1" ]]; then
        return
    fi

    log "Normalizando permissoes em ${WRITABLE_PATHS}"

    local path
    for path in ${WRITABLE_PATHS}; do
        if [[ ! -e "${ROOT_DIR}/${path}" ]]; then
            continue
        fi

        "${CHGRP_BIN}" -R "${WEB_GROUP}" "${ROOT_DIR}/${path}" 2>/dev/null || true
        "${CHMOD_BIN}" -R ug+rwX "${ROOT_DIR}/${path}"
        "${FIND_BIN}" "${ROOT_DIR}/${path}" -type d -exec "${CHMOD_BIN}" g+s {} +

        if command -v "${SETFACL_BIN}" >/dev/null 2>&1; then
            "${SETFACL_BIN}" -R -m "u:${DEPLOY_USER}:rwx" -m "u:${WEB_USER}:rwx" -m "g:${WEB_GROUP}:rwx" "${ROOT_DIR}/${path}" 2>/dev/null || true
            "${SETFACL_BIN}" -R -d -m "u:${DEPLOY_USER}:rwx" -m "u:${WEB_USER}:rwx" -m "g:${WEB_GROUP}:rwx" "${ROOT_DIR}/${path}" 2>/dev/null || true
        fi
    done
}

finish() {
    if [[ "${MAINTENANCE_MODE}" == "1" ]]; then
        log "Tirando a aplicacao do modo de manutencao"
        run_in_root "$PHP_BIN" "$ARTISAN" up || true
    fi
}

trap finish EXIT

log "Atualizando o codigo da branch ${APP_BRANCH}"
run_in_root "$GIT_BIN" pull origin main

normalize_permissions

if [[ "${MAINTENANCE_MODE}" == "1" ]]; then
    log "Colocando a aplicacao em modo de manutencao"
    run_in_root "$PHP_BIN" "$ARTISAN" down
fi

# log "Instalando dependencias do Composer"
# run_in_root "$COMPOSER_BIN" install --no-dev --optimize-autoloader

if [[ "${BUILD_FRONTEND}" == "1" ]]; then
    log "Instalando dependencias do frontend"
    run_in_root "$NPM_BIN" install

    log "Gerando build de producao do frontend"
    run_in_root "$NPM_BIN" run build
fi

log "Limpando caches do Laravel"
run_in_root "$PHP_BIN" "$ARTISAN" optimize:clear

if [[ "${RUN_MIGRATIONS}" == "1" ]]; then
    log "Executando migracoes"
    run_in_root "$PHP_BIN" "$ARTISAN" migrate --force
fi

log "Gerando caches otimizados"
run_in_root "$PHP_BIN" "$ARTISAN" config:cache
run_in_root "$PHP_BIN" "$ARTISAN" route:cache
run_in_root "$PHP_BIN" "$ARTISAN" view:cache
run_in_root "$PHP_BIN" "$ARTISAN" optimize

normalize_permissions

if [[ "${RESTART_QUEUES}" == "1" ]]; then
    log "Finalizando o Horizon de forma graciosa"
    run_in_root "$PHP_BIN" "$ARTISAN" horizon:terminate
fi

if [[ "${RELOAD_SUPERVISOR}" == "1" ]]; then
    log "Recarregando configuracao do supervisor"
    run_supervisorctl reread
    run_supervisorctl update

    for program in ${SUPERVISOR_PROGRAMS}; do
        log "Reiniciando programa do supervisor: ${program}"
        run_supervisorctl restart "$program"
    done
fi

log "Deploy concluido no ambiente ${APP_ENVIRONMENT}"
