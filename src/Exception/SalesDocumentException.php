<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Base class for domain errors of the sales document flow.
 *
 * Extends \RuntimeException so that existing call sites and tests expecting a RuntimeException
 * keep working, while the concrete subclasses let the transport layer tell apart situations that
 * deserve different HTTP statuses.
 *
 * The error code is part of the API contract and is meant to be branched on by clients; the
 * message is written for humans and may change freely.
 */
abstract class SalesDocumentException extends \RuntimeException
{
    abstract public function errorCode(): string;
}
