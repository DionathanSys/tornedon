<x-filament-panels::page>
    @php($rows = $this->rows)
    @php($companies = $this->selectedCompanies())

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
                            <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $company->name }}</th>
                        @endforeach
                        <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Consolidado</th>
                    </tr>
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
                                <td class="px-4 py-4 text-right text-sm text-gray-700 dark:text-gray-300" style="padding-block: 1rem;">
                                    {{ $this->money((float) data_get($row, 'amounts.' . $company->id, 0)) }}
                                </td>
                            @endforeach
                            <td class="px-4 py-4 text-right text-sm font-semibold text-primary-700 dark:text-primary-300" style="padding-block: 1rem; color: #1d4ed8;">
                                {{ $this->money((float) $row['total']) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $companies->count() + 2 }}" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
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
