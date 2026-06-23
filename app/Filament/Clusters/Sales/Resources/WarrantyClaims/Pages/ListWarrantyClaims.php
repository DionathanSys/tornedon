<?php

namespace App\Filament\Clusters\Sales\Resources\WarrantyClaims\Pages;

use App\Filament\Clusters\Sales\Resources\WarrantyClaims\WarrantyClaimResource;
use Filament\Resources\Pages\ListRecords;

class ListWarrantyClaims extends ListRecords
{
    protected static string $resource = WarrantyClaimResource::class;
}
