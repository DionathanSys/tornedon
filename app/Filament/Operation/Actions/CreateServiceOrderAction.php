<?php

namespace App\Filament\Operation\Actions;

use App\Filament\Clusters\Sales\Resources\Components\SelectPartner;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\Actions\CreateServiceOrderAction as SalesCreateServiceOrderAction;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderDetail;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

final class CreateServiceOrderAction
{
    public static function make(): CreateAction
    {
        return SalesCreateServiceOrderAction::make()
            ->name('createServiceOrder')
            ->label('Nova OS')
            ->hiddenLabel(false)
            ->createAnother(false)
            ->modalWidth(Width::Large)
            ->extraModalWindowAttributes(['class' => 'op-operation-modal op-create-order-modal'])
            ->modalHeading(fn (): HtmlString => new HtmlString(view('filament.operation.modals.create-service-order-heading')->render()))
            ->modalDescription('Um novo atendimento começa pelo cliente.')
            ->schema(fn (Schema $schema): Schema => $schema->components([
                SelectPartner::make('customer_id', 'customer')
                    ->label('Quem vamos atender?')
                    ->placeholder('Buscar cliente por nome ou documento')
                    ->searchPrompt('Digite o nome ou documento do cliente')
                    ->columnSpanFull(),
            ]))
            ->modalContentFooter(fn () => view('filament.operation.modals.create-service-order-hint'))
            ->modalSubmitActionLabel('Criar OS')
            ->modalSubmitAction(fn ($action) => $action->icon(Heroicon::ArrowRight))
            ->modalCancelActionLabel('Voltar')
            ->successRedirectUrl(fn (Model $record): string => ServiceOrderDetail::getUrl(
                ['record' => $record],
                tenant: Filament::getTenant(),
            ));
    }
}
