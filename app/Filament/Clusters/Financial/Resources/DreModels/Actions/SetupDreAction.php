<?php

namespace App\Filament\Clusters\Financial\Resources\DreModels\Actions;

use App\Filament\Clusters\Financial\Resources\DreModels\DreModelResource;
use App\Filament\Pages\DreReportPage;
use App\Services\Financial\Dre\SetupDreService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class SetupDreAction
{
    public static function make(): Action
    {
        return Action::make('setupDre')
            ->label('Montar minha DRE')
            ->icon('heroicon-o-sparkles')
            ->authorize(fn (): bool => DreModelResource::canCreate())
            ->modalHeading('Montar DRE gerencial')
            ->modalDescription('Confira as sugestões pelo tipo de cada conta. Cada conta entra uma única vez, sem incluir filhas automaticamente. Não é necessário cadastrar linhas, sinais ou subtotais. Descontos no recebimento serão separados automaticamente.')
            ->modalSubmitActionLabel('Criar DRE pronta para usar')
            ->fillForm(fn (): array => [
                'name' => 'DRE Gerencial', 'is_default' => true,
                'accounts' => app(SetupDreService::class)->suggestions(Filament::getTenant()->id),
            ])
            ->schema([
                TextInput::make('name')->label('Nome da DRE')->required()->maxLength(255),
                Toggle::make('is_default')->label('Usar como modelo padrão')->default(true),
                Repeater::make('accounts')->label('Onde cada conta deve aparecer?')
                    ->helperText('Todas as contas devem ser conferidas. Use “Não incluir nesta DRE” para contas patrimoniais ou sem efeito no resultado. Contas novas, criadas depois, precisam ser vinculadas ao modelo.')
                    ->addable(false)->deletable(false)->reorderable(false)->columns(2)
                    ->schema([
                        Hidden::make('account_id')->required(),
                        TextInput::make('account_name')->label('Conta do plano')->disabled()->dehydrated(false),
                        Select::make('group')->label('Grupo na DRE')->options(SetupDreService::GROUPS)->required()->native(false),
                    ]),
            ])
            ->action(function (array $data, Schema $schema, $livewire): void {
                abort_unless(DreModelResource::canCreate(), 403);
                try {
                    $model = app(SetupDreService::class)->create(Filament::getTenant()->id, $data);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->warning()
                        ->title('Não foi possível criar a DRE')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->send();

                    throw ValidationException::withMessages(
                        collect($exception->errors())
                            ->mapWithKeys(fn (array $messages, string $field): array => [
                                $schema->getStatePath().'.'.$field => $messages,
                            ])
                            ->all(),
                    );
                }
                Notification::make()->success()->title('DRE criada com contas e resultados configurados')->send();
                if ($livewire instanceof DreReportPage) {
                    $livewire->data['dre_model_id'] = $model->id;
                } else {
                    $livewire->redirect(DreModelResource::getUrl('edit', ['record' => $model]));
                }
            });
    }
}
