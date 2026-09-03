<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\SalesDocumentStatus;

/**
 * The document exists and the request is well formed, but its current status does not allow the
 * requested transition. That is a conflict with the state of the resource, hence 409 rather than
 * 422: the payload is valid, the resource simply moved on.
 */
final class SalesDocumentStatusConflict extends SalesDocumentException
{
    public static function cannotApprove(int $documentId, SalesDocumentStatus $current): self
    {
        return new self(\sprintf(
            'Sales document %d cannot be approved while it is %s.',
            $documentId,
            $current->value,
        ));
    }

    public function errorCode(): string
    {
        return 'sales_document_status_conflict';
    }
}
