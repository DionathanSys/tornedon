<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\RelationManagers\Actions;

use App\Models\Contact;
use App\Services\Contact\ContactService;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use Illuminate\Support\Facades\Log;

final class CreateContactAction
{
    public static function make(): CreateAction
    {
        return CreateAction::make()
            ->label('Novo Contato')
            ->schema([
                TextInput::make('email')
                    ->label('E-mail')
                    ->email()
                    ->required()
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label('Telefone Fixo')
                    ->tel()
                    ->mask('(99) 9999-9999')
                    ->placeholder('(00) 0000-0000')
                    ->maxLength(255),
                TextInput::make('mobile')
                    ->label('Celular')
                    ->tel()
                    ->mask('(99) 99999-9999')
                    ->placeholder('(00) 00000-0000')
                    ->maxLength(255),
                Toggle::make('notify')
                    ->label('Recebe Notificações')
                    ->default(false),
                Toggle::make('is_active')
                    ->label('Ativo')
                    ->default(true),
            ])
            ->using(function (array $data, RelationManager $livewire): ?Model {
                $companyPartner = $livewire->getOwnerRecord();
                
                Log::debug('Iniciando criação de contato via RelationManager', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'company_partner_id' => $companyPartner->id,
                    'data'               => $data,
                ]);

                $service = new ContactService();
                $contact = $service->create($companyPartner->id, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser());
                    return null;
                }

                notify::success(message: $service->getMessageUser());
                return $contact;
            });
    }
}
