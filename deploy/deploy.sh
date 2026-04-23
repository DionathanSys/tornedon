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
RESTORE_TRACKED_WRITABLE_FILES="${RESTORE_TRACKED_WRITABLE_FILES:-1}"
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
MAINTENANCE_STARTED=0

umask "${DEPLOY_UMASK}"

log() {
    printf '[deploy] %s\n' "$1"
}

run_in_root() {
    (cd "$ROOT_DIR" && "$@")
}

check_database_connection() {
    local db_check_output

    log "Validando conectividade com o banco antes das migracoes"

    if db_check_output="$(
        cd "$ROOT_DIR" && "$PHP_BIN" -r '
            require "vendor/autoload.php";

            $app = require "bootstrap/app.php";
            $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();

            $defaultConnection = config("database.default");
            $connectionConfig = config("database.connections." . $defaultConnection, []);

            $host = $connectionConfig["host"] ?? "n/a";
            $port = $connectionConfig["port"] ?? "n/a";
            $database = $connectionConfig["database"] ?? "n/a";

            try {
                Illuminate\Support\Facades\DB::connection()->getPdo();
                echo sprintf(
                    "Conexao %s pronta (%s:%s / %s)\n",
                    $defaultConnection,
                    $host,
                    $port,
                    $database
                );
            } catch (Throwable $e) {
                fwrite(
                    STDERR,
                    sprintf(
                        "Falha ao conectar usando %s (%s:%s / %s): %s\n",
                        $defaultConnection,
                        $host,
                        $port,
                        $database,
                        $e->getMessage()
                    )
                );
                exit(1);
            }
        ' 2>&1
    )"; then
        printf '%s\n' "${db_check_output}"
        return 0
    fi

    printf '%s\n' "${db_check_output}" >&2
    log "Abortando deploy antes do modo de manutencao porque o banco nao respondeu."
    return 1
}

run_supervisorctl() {
    # Split the configured command so values like "sudo supervisorctl" work as expected.
    read -r -a supervisor_cmd <<< "$SUPERVISORCTL_BIN"
    (cd "$ROOT_DIR" && "${supervisor_cmd[@]}" "$@")
}

restart_supervisor_programs() {
    local program

    for program in ${SUPERVISOR_PROGRAMS}; do
        log "Reiniciando programa do supervisor: ${program}"
        run_supervisorctl restart "$program"
    done
}

terminate_horizon_gracefully() {
    local horizon_output
    local horizon_status=0

    log "Finalizando o Horizon de forma graciosa"

    if horizon_output="$(run_in_root "$PHP_BIN" "$ARTISAN" horizon:terminate 2>&1)"; then
        horizon_status=0
    else
        horizon_status=$?
    fi

    if [[ -n "${horizon_output}" ]]; then
        printf '%s\n' "${horizon_output}"
    fi

    if [[ "${horizon_status}" -ne 0 ]]; then
        log "Aviso: o comando horizon:terminate retornou erro (${horizon_status})."
    fi

    if grep -Fq "Failed to kill process" <<< "${horizon_output}" || grep -Fq "Operation not permitted" <<< "${horizon_output}"; then
        log "Aviso: o Horizon nao conseguiu encerrar todos os workers com o usuario atual."

        if [[ "${RELOAD_SUPERVISOR}" == "1" ]]; then
            log "Aplicando fallback com restart via supervisor"
            restart_supervisor_programs
            return
        fi

        log "Se o Horizon estiver sob o Supervisor com outro usuario, rode o deploy com RELOAD_SUPERVISOR=1."
        return
    fi

    if [[ "${horizon_status}" -ne 0 ]]; then
        return "${horizon_status}"
    fi
}

ensure_no_unmerged_files() {
    local unmerged_files

    unmerged_files="$(run_in_root "$GIT_BIN" diff --name-only --diff-filter=U || true)"

    if [[ -n "${unmerged_files}" ]]; then
        log "Foram encontrados arquivos com merge pendente. Resolva os conflitos antes de rodar o deploy."
        printf '%s\n' "${unmerged_files}" >&2
        exit 1
    fi
}

restore_tracked_writable_files() {
    if [[ "${RESTORE_TRACKED_WRITABLE_FILES}" != "1" ]]; then
        return
    fi

    local tracked_files=()
    local tracked_file
    local path

    for path in ${WRITABLE_PATHS}; do
        if [[ ! -e "${ROOT_DIR}/${path}" ]]; then
            continue
        fi

        while IFS= read -r tracked_file; do
            if [[ -n "${tracked_file}" ]]; then
                tracked_files+=("${tracked_file}")
            fi
        done < <(run_in_root "$GIT_BIN" ls-files -- "${path}" || true)
    done

    if [[ "${#tracked_files[@]}" -eq 0 ]]; then
        return
    fi

    log "Restaurando arquivos versionados dentro de ${WRITABLE_PATHS}"
    run_in_root "$GIT_BIN" restore --worktree --source=HEAD -- "${tracked_files[@]}"
}

normalize_permissions() {
    if [[ "${FIX_PERMISSIONS}" != "1" ]]; then
        return
    fi

    log "Normalizando permissoes em ${WRITABLE_PATHS}"

    local permission_warnings=0
    local path
    for path in ${WRITABLE_PATHS}; do
        if [[ ! -e "${ROOT_DIR}/${path}" ]]; then
            continue
        fi

        "${CHGRP_BIN}" -R "${WEB_GROUP}" "${ROOT_DIR}/${path}" 2>/dev/null || true
        if ! "${CHMOD_BIN}" -R ug+rwX "${ROOT_DIR}/${path}" 2>/dev/null; then
            permission_warnings=1
        fi

        if ! "${FIND_BIN}" "${ROOT_DIR}/${path}" -type d -exec "${CHMOD_BIN}" g+s {} + 2>/dev/null; then
            permission_warnings=1
        fi

        if command -v "${SETFACL_BIN}" >/dev/null 2>&1; then
            "${SETFACL_BIN}" -R -m "u:${DEPLOY_USER}:rwx" -m "u:${WEB_USER}:rwx" -m "g:${WEB_GROUP}:rwx" "${ROOT_DIR}/${path}" 2>/dev/null || true
            "${SETFACL_BIN}" -R -d -m "u:${DEPLOY_USER}:rwx" -m "u:${WEB_USER}:rwx" -m "g:${WEB_GROUP}:rwx" "${ROOT_DIR}/${path}" 2>/dev/null || true
        fi
    done

    if [[ "${permission_warnings}" == "1" ]]; then
        log "Aviso: alguns arquivos de ${WRITABLE_PATHS} nao pertencem ao usuario ${DEPLOY_USER} e nao puderam ter as permissoes ajustadas."
    fi
}

finish() {
    if [[ "${MAINTENANCE_MODE}" == "1" && "${MAINTENANCE_STARTED}" == "1" ]]; then
        log "Tirando a aplicacao do modo de manutencao"
        run_in_root "$PHP_BIN" "$ARTISAN" up || true
    fi
}

trap finish EXIT

ensure_no_unmerged_files
restore_tracked_writable_files

log "Atualizando o codigo da branch ${APP_BRANCH}"
run_in_root "$GIT_BIN" pull origin "$APP_BRANCH"

normalize_permissions

if [[ "${RUN_MIGRATIONS}" == "1" ]]; then
    check_database_connection
fi

if [[ "${MAINTENANCE_MODE}" == "1" ]]; then
    log "Colocando a aplicacao em modo de manutencao"
    run_in_root "$PHP_BIN" "$ARTISAN" down
    MAINTENANCE_STARTED=1
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
    terminate_horizon_gracefully
fi

if [[ "${RELOAD_SUPERVISOR}" == "1" ]]; then
    log "Recarregando configuracao do supervisor"
    run_supervisorctl reread
    run_supervisorctl update

    restart_supervisor_programs
fi

log "Deploy concluido no ambiente ${APP_ENVIRONMENT}"
