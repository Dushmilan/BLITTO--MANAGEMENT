<?php

namespace App\Domain\Vault;

use App\Models\Document;
use App\Models\FilingRecord;

/**
 * Filing boundary rule (CONTEXT.md invariant 3): once a document's
 * application has been filed with the patent office, the document is
 * immutable — only new documents may be added.
 */
class DocumentImmutability
{
    /**
     * @throws DocumentIsImmutable when the document's application is already filed
     */
    public function assertModifiable(Document $document): void
    {
        $filed = FilingRecord::query()
            ->where('application_id', $document->application_id)
            ->whereNotNull('filed_date')
            ->exists();

        if ($filed) {
            throw new DocumentIsImmutable(
                "Document {$document->id} is immutable: its application has been filed"
            );
        }
    }
}
