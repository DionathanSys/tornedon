<?php

namespace App\Enum\Invoice;

enum InvoiceAction: string
{
    case EDIT_COMPOSITION = 'edit_composition';
    case CONFIRM = 'confirm';
    case RETURN_TO_DRAFT = 'return_to_draft';
    case DELETE = 'delete';
}
