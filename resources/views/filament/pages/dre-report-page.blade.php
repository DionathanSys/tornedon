<x-filament-panels::page>
    @php($rows = $this->rows)
    @php($companies = $this->selectedCompanies())

    <div class="space-y-8" style="display: grid; gap: 2rem;">
        <form wire:submit="applyFilters" class="space-y-5" style="display: grid; gap: 1.5rem;">
            {{ $this->form }}

            <div>
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="applyFilters">
                    Aplicar filtros
                </x-filament::button>
            </div>
        </form>

        <div class="overflow-x-auto rounded-xl border border-primary-200 bg-white shadow-sm dark:border-primary-500/20 dark:bg-white/5" style="border-color: #93c5fd;">
            <div class="flex items-center justify-between gap-3 border-b border-primary-200 bg-primary-50 px-4 py-3 dark:border-primary-500/20 dark:bg-primary-500/10" style="border-color: #bfdbfe; background-color: #eff6ff;">
                <div>
                    <h2 class="text-sm font-semibold text-primary-950 dark:text-primary-100" style="color: #1e3a8a;">Resultado da DRE</h2>
                </div>
                <span class="rounded-full bg-primary-100 px-2.5 py-1 text-xs font-medium text-primary-700 dark:bg-primary-500/20 dark:text-primary-300" style="background-color: #dbeafe; color: #1d4ed8;">{{ $rows->count() }} linhas</span>
            </div>
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                <thead class="bg-primary-50/70 dark:bg-primary-500/10" style="background-color: #f8fbff;">
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
                        <tr @class(['bg-primary-50/70 dark:bg-primary-500/10' => $row['is_bold'], 'hover:bg-primary-50/40 dark:hover:bg-primary-500/5' => ! $row['is_bold']]) style="{{ $row['is_bold'] ? 'background-color: #eff6ff;' : '' }}">
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
        </div>
    </div>
</x-filament-panels::page>
