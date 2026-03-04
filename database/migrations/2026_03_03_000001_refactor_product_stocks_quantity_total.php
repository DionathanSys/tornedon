<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refatora o controle de estoque para separar saldo fiscal do saldo disponível.
 *
 * Antes:
 *   - quantity_available  → saldo físico total (real, gravado)
 *   - quantity_reserved   → saldo reservado (real, gravado)
 *   - total_cost          → quantidade_available * custo_médio (virtual)
 *
 * Depois:
 *   - quantity_total      → saldo fiscal/físico total (real, gravado) ← renomeado
 *   - quantity_reserved   → saldo reservado (real, gravado, sem alteração)
 *   - quantity_available  → saldo disponível (virtual: quantity_total - quantity_reserved)
 *   - total_cost          → custo do saldo disponível (virtual: quantity_available * average_cost)
 *
 * Fluxo de requisição atualizado:
 *   - OPEN   (item add/edit/del) → listeners ajustam quantity_reserved diretamente
 *   - CLOSED (encerrada)         → apenas transição de estado (sem movimentos de reserva adicionais)
 *   - INVOICED (faturada)        → EXIT decrementa quantity_total + decrementa quantity_reserved diretamente
 *   - CANCELLED / REOPEN         → libera quantity_reserved via items
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Passo 1: remove total_cost (depende de quantity_available) ──────
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropColumn('total_cost');
        });

        // ── Passo 2: renomeia quantity_available → quantity_total ────────────
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->renameColumn('quantity_available', 'quantity_total');
        });

        // ── Passo 3: adiciona quantity_available virtual + recria total_cost ──
        Schema::table('product_stocks', function (Blueprint $table) {
            // Saldo disponível = total fiscal − reservado (coluna virtual, somente leitura)
            $table->decimal('quantity_available', 15, 3)
                ->virtualAs('quantity_total - quantity_reserved')
                ->after('quantity_total');

            // Custo total do saldo disponível
            $table->decimal('total_cost', 15, 4)
                ->virtualAs('(quantity_total - quantity_reserved) * average_cost')
                ->after('average_cost');
        });
    }

    public function down(): void
    {
        // ── Remove colunas virtuais criadas nesta migration ──────────────────
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropColumn(['total_cost', 'quantity_available']);
        });

        // ── Reverte o rename quantity_total → quantity_available ─────────────
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->renameColumn('quantity_total', 'quantity_available');
        });

        // ── Recria total_cost original ───────────────────────────────────────
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->decimal('total_cost', 15, 4)
                ->virtualAs('quantity_available * average_cost')
                ->after('average_cost');
        });
    }
};
