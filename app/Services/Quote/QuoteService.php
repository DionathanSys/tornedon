<?php

namespace App\Services\Quote;

use App\Models\Quote;
use App\Services\Quote\Actions\ApproveQuote;
use App\Services\Quote\Actions\ConvertToProductionOrder;
use App\Services\Quote\Actions\CreateQuote;
use App\Services\Quote\Actions\RejectQuote;
use App\Services\Quote\Actions\SendForApproval;
use App\Traits\HandlesServiceResponse;

class QuoteService
{
    use HandlesServiceResponse;

    public function create(array $data, int $createdBy): ?Quote
    {
        $action = new CreateQuote($createdBy);
        $quote = $action->execute($data);

        if ($action->isSuccess()) {
            $this->setSuccess('Orçamento criado com sucesso');
            return $quote;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return null;
    }

    public function sendForApproval(Quote $quote, int $userId): bool
    {
        $action = new SendForApproval($userId);
        $result = $action->execute($quote);

        if ($action->isSuccess()) {
            $this->setSuccess('Orçamento enviado para aprovação');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }

    public function approve(Quote $quote, int $approvedBy): bool
    {
        $action = new ApproveQuote($approvedBy);
        $result = $action->execute($quote);

        if ($action->isSuccess()) {
            $this->setSuccess('Orçamento aprovado com sucesso');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }

    public function reject(Quote $quote, string $reason, int $rejectedBy): bool
    {
        $action = new RejectQuote($rejectedBy);
        $result = $action->execute($quote, $reason);

        if ($action->isSuccess()) {
            $this->setSuccess('Orçamento rejeitado');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }

    public function convertToProductionOrder(Quote $quote, array $data, int $createdBy): mixed
    {
        $action = new ConvertToProductionOrder($createdBy);
        $productionOrder = $action->execute($quote, $data);

        if ($action->isSuccess()) {
            $this->setSuccess('Ordem de produção criada com sucesso');
            return $productionOrder;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return null;
    }
}
