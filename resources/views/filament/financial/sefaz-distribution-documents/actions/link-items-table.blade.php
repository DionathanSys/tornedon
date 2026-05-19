@livewire(
    \App\Livewire\SefazDistributionDocumentItemsTable::class,
    ['documentId' => $document->id],
    key('sefaz-distribution-document-items-table-' . $document->id),
)
