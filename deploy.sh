#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ -f "${SCRIPT_DIR}/artisan" ]]; then
    PROJECT_ROOT="${SCRIPT_DIR}"
elif [[ -f "${SCRIPT_DIR}/../artisan" ]]; then
    PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
else
    PROJECT_ROOT="$(pwd)"
fi

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NPM_BIN="${NPM_BIN:-npm}"
APP_ENVIRONMENT="${APP_ENVIRONMENT:-production}"
MAINTENANCE_MODE="${MAINTENANCE_MODE:-1}"
BUILD_FRONTEND="${BUILD_FRONTEND:-0}"
RUN_MIGRATIONS="${RUN_MIGRATIONS:-1}"
RESTART_QUEUES="${RESTART_QUEUES:-1}"
RELOAD_SUPERVISOR="${RELOAD_SUPERVISOR:-0}"
SUPERVISORCTL_BIN="${SUPERVISORCTL_BIN:-sudo supervisorctl}"
SUPERVISOR_PROGRAMS="${SUPERVISOR_PROGRAMS:-tornedon-queue tornedon-schedule}"

log() {
    printf '\n[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1"
}

finish() {
    if [[ "${MAINTENANCE_MODE}" == "1" && -f "${PROJECT_ROOT}/artisan" ]]; then
        cd "${PROJECT_ROOT}"
        log "Removendo modo manutencao"
        "${PHP_BIN}" artisan up || true
    fi
}

trap finish EXIT

cd "${PROJECT_ROOT}"

if [[ ! -f artisan ]]; then
    echo "Arquivo artisan nao encontrado em ${PROJECT_ROOT}" >&2
    exit 1
fi

log "Iniciando deploy em ${PROJECT_ROOT}"

if [[ "${MAINTENANCE_MODE}" == "1" ]]; then
    log "Ativando modo manutencao"
    "${PHP_BIN}" artisan down --retry=60
fi

log "Instalando dependencias PHP"
"${COMPOSER_BIN}" install --no-interaction --no-dev --prefer-dist --optimize-autoloader

if [[ "${BUILD_FRONTEND}" == "1" ]]; then
    if [[ -f package.json ]]; then
        log "Instalando dependencias frontend"
        "${NPM_BIN}" ci

        log "Gerando build frontend"
        "${NPM_BIN}" run build
    else
        log "Frontend ignorado: package.json nao encontrado"
    fi
fi

log "Limpando caches"
"${PHP_BIN}" artisan optimize:clear

if [[ "${RUN_MIGRATIONS}" == "1" ]]; then
    log "Executando migrations"
    "${PHP_BIN}" artisan migrate --force
fi

log "Recriando caches de producao"
"${PHP_BIN}" artisan config:cache
"${PHP_BIN}" artisan route:cache
"${PHP_BIN}" artisan view:cache

if [[ "${APP_ENVIRONMENT}" == "production" ]]; then
    log "Aplicando otimizacao final"
    "${PHP_BIN}" artisan optimize
fi

if [[ "${RESTART_QUEUES}" == "1" ]]; then
    log "Reiniciando workers da fila"
    "${PHP_BIN}" artisan queue:restart
fi

if [[ "${RELOAD_SUPERVISOR}" == "1" ]]; then
    log "Recarregando configuracao do supervisor"
    bash -lc "${SUPERVISORCTL_BIN} reread"
    bash -lc "${SUPERVISORCTL_BIN} update"

    for program in ${SUPERVISOR_PROGRAMS}; do
        log "Reiniciando programa do supervisor: ${program}"
        bash -lc "${SUPERVISORCTL_BIN} restart ${program}:*" || bash -lc "${SUPERVISORCTL_BIN} restart ${program}"
    done
fi

log "Deploy concluido com sucesso"
