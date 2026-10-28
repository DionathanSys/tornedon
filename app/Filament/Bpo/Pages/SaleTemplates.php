<?php

namespace App\Filament\Bpo\Pages;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\OperationNature;
use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Models\SaleTemplate;
use App\Services\Access\UserAccessService;
use App\Services\Bpo\QuickSaleWorkflow;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class SaleTemplates extends Page implements HasTable
{
    use HasWorkbenchHeader;
    use InteractsWithTable;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.sale-templates';

    protected static ?string $title = 'Modelos de venda';

    public function workbenchEyebrow(): string
    {
        return 'VENDAS · CONFIGURAÇÃO';
    }

    public function workbenchDescription(): string
    {
        return 'Configure os modelos usados na venda rápida. CFOP e tributos seguem as regras fiscais da empresa e dos itens.';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() && app(UserAccessService::class)->allows(Filament::auth()->user(), Filament::getTenant(), 'sale_templates.view', 'bpo');
    }

    public function booted(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    private function templateAction(string $name, bool $editing = false): Action
    {
        return Action::make($name)->label($editing ? 'Editar' : 'Novo modelo')->modalHeading('Modelo de venda')
            ->visible(fn (?SaleTemplate $record) => app(UserAccessService::class)->allows(Filament::auth()->user(), Filament::getTenant(), 'sale_templates.'.($editing ? 'update' : 'create'), 'bpo'))
            ->fillForm(fn (?SaleTemplate $record) => $record?->toArray() ?? [])
            ->schema([
                TextInput::make('name')->label('Nome')->required()->maxLength(255),
                Select::make('document_type')->label('Documento')->options(DocumentModel::toSelectArray())->required()->live(),
                Select::make('operation_nature')->label('Natureza da operação NF-e')->placeholder('Automática conforme UF do cliente')
                    ->options([OperationNature::VENDA_DENTRO_ESTADO->value => 'Venda dentro do estado', OperationNature::VENDA_FORA_ESTADO->value => 'Venda fora do estado'])->nullable(),
                Toggle::make('stock_control_enabled')->label('Controlar estoque dos produtos')->default(false)
                    ->helperText('A requisição usa as reservas e a saída após autorização fiscal do fluxo existente. Serviços não movimentam estoque.'),
                Textarea::make('additional_information')->label('Informações adicionais da NFS-e')->maxLength(2000),
                Toggle::make('is_active')->label('Ativo')->default(true),
            ])->action(function (array $data, ?SaleTemplate $record) use ($editing): void {
                $workflow = app(QuickSaleWorkflow::class);
                $company = Filament::getTenant();
                $workflow->authorize(Filament::auth()->user(), $company, 'sale_templates.'.($editing ? 'update' : 'create'), $record);
                $data = validator($data, [
                    'name' => ['required', 'string', 'max:255', Rule::unique('sale_templates')->where('company_id', $company->id)->ignore($record?->id)],
                    'document_type' => ['required', Rule::enum(DocumentModel::class)],
                    'operation_nature' => ['nullable', Rule::in([OperationNature::VENDA_DENTRO_ESTADO->value, OperationNature::VENDA_FORA_ESTADO->value])],
                    'stock_control_enabled' => 'required|boolean', 'is_active' => 'required|boolean', 'additional_information' => 'nullable|string|max:2000',
                ])->validate();
                if ($data['document_type'] === DocumentModel::NFSE->value) {
                    $data['stock_control_enabled'] = false;
                    $data['operation_nature'] = null;
                }
                if ($editing) {
                    $record->update($data);
                } else {
                    SaleTemplate::create(Arr::only($data, ['name', 'document_type', 'operation_nature', 'stock_control_enabled', 'is_active', 'additional_information'])
                        + ['company_id' => $company->id, 'created_by' => Filament::auth()->id()]);
                }
            });
    }

    protected function getHeaderActions(): array
    {
        return [$this->templateAction('newTemplate')];
    }

    public function table(Table $table): Table
    {
        return $table->query(SaleTemplate::where('company_id', Filament::getTenant()->id))->columns([
            TextColumn::make('name')->label('Modelo')->searchable(),
            TextColumn::make('document_type')->label('Documento')->formatStateUsing(fn ($state) => $state->description()),
            TextColumn::make('stock_control_enabled')->label('Estoque')->formatStateUsing(fn ($state) => $state ? 'Controlado' : 'Sem controle'),
            TextColumn::make('is_active')->label('Situação')->formatStateUsing(fn ($state) => $state ? 'Ativo' : 'Inativo'),
        ])->recordActions([$this->templateAction('editTemplate', true)])->defaultSort('name');
    }
}
