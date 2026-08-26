<?php

return [
    'nacional:default' => \App\Services\FiscalDocument\Actions\BuildNfseNacionalPayloadAction::class,
    'municipal:default' => \App\Services\FiscalDocument\Actions\BuildNfseMunicipalPayloadAction::class,
    'municipal:4212908' => \App\Services\FiscalDocument\Actions\BuildNfsePinhalzinhoIpmPayloadAction::class,
];
