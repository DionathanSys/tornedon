<?php

namespace App\Models;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\OperationNature;
use Illuminate\Database\Eloquent\Model;

class SaleTemplate extends Model
{
    protected $fillable = ['company_id', 'name', 'document_type', 'operation_nature', 'stock_control_enabled', 'is_active', 'additional_information', 'created_by'];

    protected $casts = ['document_type' => DocumentModel::class, 'operation_nature' => OperationNature::class, 'stock_control_enabled' => 'boolean', 'is_active' => 'boolean'];
}
