<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The requested document does not exist. Caused by the client asking for an unknown id, so it maps
 * to 404 and never to a server error.
 */
final class SalesDocumentNotFound extends SalesDocumentException
{
    public static function withId(int $documentId): self
    {
        return new self(\sprintf('Sales document %d was not found.', $documentId));
    }

    public function errorCode(): string
    {
        return 'sales_document_not_found';
    }
}
