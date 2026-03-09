<?php

namespace App\Services\FiscalDocumentItem\Actions;

use App\Models\FiscalDocumentItem;
use App\Services\FiscalDocument\Validators\Items\NfeItemValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateFiscalDocumentItemAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    public function execute(array $data): ?FiscalDocumentItem
    {
        try {
            $validated = NfeItemValidator::validateCreate($data);
            $validated['created_by'] = $this->createdBy;

            $item = FiscalDocumentItem::create($validated);

            Log::info('Item de documento fiscal criado com sucesso', [
                'metodo'                    => __METHOD__ . '@' . __LINE__,
                'fiscal_document_item_id'   => $item->id,
                'fiscal_document_id'        => $item->fiscal_document_id,
            ]);

            $this->setSuccess();
            return $item;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados do item', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao salvar item do documento fiscal no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'data'          => $data,
                'user_id'       => $this->createdBy,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar item do documento fiscal');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'message'       => $this->getMessage(),
                'error_code'    => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
                'data'          => $data,
                'user_id'       => $this->createdBy,
            ]);

            return null;
        }
    }
}
