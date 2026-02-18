<?php

namespace App\Enum\Payment;

enum Method: string
{
    case CASH = 'dinheiro';
    case CREDIT_CARD = 'cartao_credito';
    case DEBIT_CARD = 'cartao_debito';
    case PIX = 'pix';
    case BANK_SLIP = 'boleto';
    case BANK_TRANSFER = 'transferencia';
    case CHECK = 'cheque';
    case STORE_CREDIT = 'crediario';
    case DEPOSIT = 'deposito';
    case OTHER = 'outros';

    public function description(): string
    {
        return match ($this) {
            self::CASH => 'Dinheiro',
            self::CREDIT_CARD => 'Cartão de Crédito',
            self::DEBIT_CARD => 'Cartão de Débito',
            self::PIX => 'PIX',
            self::BANK_SLIP => 'Boleto Bancário',
            self::BANK_TRANSFER => 'Transferência Bancária',
            self::CHECK => 'Cheque',
            self::STORE_CREDIT => 'Crediário',
            self::DEPOSIT => 'Depósito',
            self::OTHER => 'Outros',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CASH => 'heroicon-o-banknotes',
            self::CREDIT_CARD => 'heroicon-o-credit-card',
            self::DEBIT_CARD => 'heroicon-o-credit-card',
            self::PIX => 'heroicon-o-qr-code',
            self::BANK_SLIP => 'heroicon-o-document-text',
            self::BANK_TRANSFER => 'heroicon-o-arrows-right-left',
            self::CHECK => 'heroicon-o-document-check',
            self::STORE_CREDIT => 'heroicon-o-shopping-cart',
            self::DEPOSIT => 'heroicon-o-building-library',
            self::OTHER => 'heroicon-o-ellipsis-horizontal',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CASH => 'success',
            self::CREDIT_CARD => 'info',
            self::DEBIT_CARD => 'primary',
            self::PIX => 'warning',
            self::BANK_SLIP => 'gray',
            self::BANK_TRANSFER => 'info',
            self::CHECK => 'gray',
            self::STORE_CREDIT => 'warning',
            self::DEPOSIT => 'primary',
            self::OTHER => 'gray',
        };
    }

    /**
     * Retorna se a forma de pagamento é à vista
     */
    public function isCashPayment(): bool
    {
        return in_array($this, [
            self::CASH,
            self::PIX,
            self::DEBIT_CARD,
        ]);
    }

    /**
     * Retorna se a forma de pagamento permite parcelamento
     */
    public function allowsInstallments(): bool
    {
        return in_array($this, [
            self::CREDIT_CARD,
            self::STORE_CREDIT,
            self::BANK_SLIP,
        ]);
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())->mapWithKeys(fn($case) => [
            $case->value => $case->description(),
        ])->toArray();
    }
}
