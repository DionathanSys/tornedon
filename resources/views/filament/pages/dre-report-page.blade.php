<x-filament-panels::page>
    @php($rows = $this->rows)
    @php($companies = $this->selectedCompanies())
    @php($isComparative = $this->isComparative())
    @php($isSeparated = $this->isSeparated())

    <style>
        .dre-report-table thead {
            background-color: #1e293b;
        }

        .dre-report-table thead th {
            color: #e2e8f0;
        }

        .dre-report-table tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .dre-report-table tbody tr:hover {
            background-color: #f1f5f9;
        }

        .dre-report-table tbody .dre-summary-row {
            background-color: #e2e8f0;
        }

        @media (prefers-color-scheme: dark) {
            .dre-report-table tbody tr:nth-child(even) {
                background-color: rgb(255 255 255 / 0.04);
            }

            .dre-report-table tbody tr:hover {
                background-color: rgb(255 255 255 / 0.08);
            }

            .dre-report-table tbody .dre-summary-row {
                background-color: rgb(255 255 255 / 0.1);
            }
        }
    </style>

    <div class="space-y-8" style="display: grid; gap: 2rem;">
        <form wire:submit="applyFilters" class="space-y-5" style="display: grid; gap: 1.5rem;">
            {{ $this->form }}

            <div>
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="applyFilters">
                    Aplicar filtros
                </x-filament::button>
            </div>
        </form>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
            <table class="dre-report-table min-w-full divide-y divide-gray-200 dark:divide-white/10">
                <thead>
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Linha</th>
                        @foreach($companies as $company)
                            <th @if($isComparative || $isSeparated) colspan="2" @endif class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $company->name }}</th>
                        @endforeach
                        @if($isComparative)
                            <th colspan="2" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Consolidado</th>
                            <th rowspan="2" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Variação R$</th>
                            <th rowspan="2" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Variação %</th>
                            <th rowspan="2" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">% Receita</th>
                        @else
                            @if($isSeparated)
                                <th colspan="2" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Consolidado</th>
                            @else
                                <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Consolidado</th>
                            @endif
                        @endif
                    </tr>
                    @if($isComparative)
                        <tr>
                            @foreach($companies as $company)
                                <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $this->dateRangeLabel('comparison_date_range') }}</th>
                                <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $this->dateRangeLabel('date_range') }}</th>
                            @endforeach
                            <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $this->dateRangeLabel('comparison_date_range') }}</th>
                            <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $this->dateRangeLabel('date_range') }}</th>
                        </tr>
                    @elseif($isSeparated)
                        <tr>
                            @foreach($companies as $company)
                                <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Previsto</th>
                                <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Realizado</th>
                            @endforeach
                            <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Previsto</th>
                            <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Realizado</th>
                        </tr>
                    @endif
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse($rows as $row)
                        <tr @class(['dre-summary-row' => $row['is_bold']])>
                            <td @class(['px-4 py-4 text-sm text-gray-900 dark:text-white', 'font-semibold' => $row['is_bold'], 'font-medium' => ! $row['is_bold']]) style="padding-block: 1rem;">
                                <span style="padding-left: {{ (int) $row['depth'] * 1.25 }}rem">
                                @if($row['code'])
                                    <span class="text-gray-500 dark:text-gray-400">{{ $row['code'] }}</span>
                                @endif
                                {{ $row['name'] }}
                                </span>
                            </td>
                            @foreach($companies as $company)
                                @if($isComparative)
                                    <td class="px-4 py-4 text-right text-sm text-gray-700 dark:text-gray-300" style="padding-block: 1rem;">
                                        {{ $this->money((float) data_get($row, 'comparison_amounts.' . $company->id, 0)) }}
                                    </td>
                                @elseif($isSeparated)
                                    <td class="px-4 py-4 text-right text-sm text-gray-700 dark:text-gray-300" style="padding-block: 1rem;">
                                        {{ $this->money((float) data_get($row, 'amounts.' . $company->id, 0)) }}
                                    </td>
                                    <td class="px-4 py-4 text-right text-sm text-gray-700 dark:text-gray-300" style="padding-block: 1rem;">
                                        {{ $this->money((float) data_get($row, 'realized_amounts.' . $company->id, 0)) }}
                                    </td>
                                @endif
                                @if(! $isSeparated)
                                    <td class="px-4 py-4 text-right text-sm text-gray-700 dark:text-gray-300" style="padding-block: 1rem;">
                                        {{ $this->money((float) data_get($row, 'amounts.' . $company->id, 0)) }}
                                    </td>
                                @endif
                            @endforeach
                            @if($isComparative)
                                <td class="px-4 py-4 text-right text-sm font-semibold text-primary-700 dark:text-primary-300" style="padding-block: 1rem; color: #1d4ed8;">
                                    {{ $this->money((float) $row['comparison_total']) }}
                                </td>
                            @endif
                            @if($isSeparated)
                                <td class="px-4 py-4 text-right text-sm font-semibold text-primary-700 dark:text-primary-300" style="padding-block: 1rem; color: #1d4ed8;">
                                    {{ $this->money((float) $row['total']) }}
                                </td>
                                <td class="px-4 py-4 text-right text-sm font-semibold text-primary-700 dark:text-primary-300" style="padding-block: 1rem; color: #1d4ed8;">
                                    {{ $this->money((float) $row['realized_total']) }}
                                </td>
                            @else
                                <td class="px-4 py-4 text-right text-sm font-semibold text-primary-700 dark:text-primary-300" style="padding-block: 1rem; color: #1d4ed8;">
                                    {{ $this->money((float) $row['total']) }}
                                </td>
                            @endif
                            @if($isComparative)
                                <td @class(['px-4 py-4 text-right text-sm font-semibold', 'text-success-600 dark:text-success-400' => $row['variation_amount'] > 0, 'text-danger-600 dark:text-danger-400' => $row['variation_amount'] < 0, 'text-gray-700 dark:text-gray-300' => $row['variation_amount'] == 0]) style="padding-block: 1rem;">
                                    {{ $this->money((float) $row['variation_amount']) }}
                                </td>
                                <td @class(['px-4 py-4 text-right text-sm font-semibold', 'text-success-600 dark:text-success-400' => ($row['variation_percentage'] ?? 0) > 0, 'text-danger-600 dark:text-danger-400' => ($row['variation_percentage'] ?? 0) < 0, 'text-gray-700 dark:text-gray-300' => ($row['variation_percentage'] ?? 0) == 0]) style="padding-block: 1rem;">
                                    {{ $this->percentage($row['variation_percentage']) }}
                                </td>
                                <td class="px-4 py-4 text-right text-sm text-gray-700 dark:text-gray-300" style="padding-block: 1rem;">
                                    {{ $this->percentage($row['percentage']) }}
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $isComparative ? ($companies->count() * 2) + 6 : ($isSeparated ? ($companies->count() * 2) + 3 : $companies->count() + 2) }}" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                Nenhum resultado encontrado. Confirme o período, a classificação das contas e os vínculos das contas nas linhas do modelo.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="flex items-center justify-between gap-3 border-t border-gray-200 px-4 py-3 dark:border-white/10">
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Resultado da DRE</h2>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $rows->count() }} linhas</span>
            </div>
        </div>
    </div>
</x-filament-panels::page>
