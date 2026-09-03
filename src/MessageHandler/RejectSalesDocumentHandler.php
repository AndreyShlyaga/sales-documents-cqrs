<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\SalesDocumentStatus;
use App\Exception\SalesDocumentNotFound;
use App\Exception\SalesDocumentStatusConflict;
use App\Message\Command\RejectSalesDocument;
use App\Repository\SalesDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Rejects a draft sales document.
 *
 * Only a draft can be rejected; an approved or already rejected document is a status conflict.
 * Nothing is spawned and nobody is notified, so there is no result to return.
 *
 * The command carries rejectedBy because the supplied test passes it by name, but the handler does
 * not persist it: the test asserts the status only, and the implementation follows the test strictly.
 * Storing who rejected the document and when, as approval does, would need two columns and a
 * migration; see README for why that was left out.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RejectSalesDocumentHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SalesDocumentRepository $repository,
    ) {
    }

    public function __invoke(RejectSalesDocument $command): void
    {
        $document = $this->repository->find($command->documentId);
        if ($document === null) {
            throw SalesDocumentNotFound::withId($command->documentId);
        }
        if ($document->getStatus() !== SalesDocumentStatus::Draft) {
            throw SalesDocumentStatusConflict::cannotReject($command->documentId, $document->getStatus());
        }

        $document->setStatus(SalesDocumentStatus::Rejected);

        $this->entityManager->flush();
    }
}
