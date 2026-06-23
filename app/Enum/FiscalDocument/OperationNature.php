<?php

namespace App\Enum\FiscalDocument;

enum OperationNature: string
{
    case VENDA_DENTRO_ESTADO = 'VENDA DENTRO DO ESTADO';
    case VENDA_FORA_ESTADO = 'VENDA FORA DO ESTADO';
    case DEVOLUCAO_COMPRA = 'DEVOLUÇÃO DE COMPRA';
    case REMESSA_GARANTIA = 'REMESSA EM GARANTIA';
    case REMESSA_CONSERTO = 'REMESSA PARA CONSERTO';
    case RETORNO_CONSERTO = 'RETORNO DE CONSERTO';
    case REMESSA_DEMONSTRACAO = 'REMESSA PARA DEMONSTRAÇÃO';
    case RETORNO_DEMONSTRACAO = 'RETORNO DE DEMONSTRAÇÃO';
    case TRANSFERENCIA = 'TRANSFERÊNCIA';
    case BONIFICACAO = 'BONIFICAÇÃO';
    case SIMPLES_REMESSA = 'SIMPLES REMESSA';

    public function description(): string
    {
        return $this->value;
    }

    public function color(): string
    {
        return match ($this) {
            self::VENDA_DENTRO_ESTADO,
            self::VENDA_FORA_ESTADO => 'success',
            self::DEVOLUCAO_COMPRA => 'danger',
            self::REMESSA_GARANTIA,
            self::REMESSA_CONSERTO,
            self::RETORNO_CONSERTO => 'warning',
            self::REMESSA_DEMONSTRACAO,
            self::RETORNO_DEMONSTRACAO => 'info',
            self::TRANSFERENCIA,
            self::BONIFICACAO,
            self::SIMPLES_REMESSA => 'gray',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
