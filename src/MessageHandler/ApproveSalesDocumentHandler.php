<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\SalesDocument;
use App\Enum\SalesDocumentStatus;
use App\Enum\SalesDocumentType;
use App\Message\Command\ApproveSalesDocument;
use App\Notification\ApprovalNotifier;
use App\Repository\SalesDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ApproveSalesDocumentHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SalesDocumentRepository $repository,
        private readonly ApprovalNotifier $approvalNotifier,
    ) {
    }

    public function __invoke(ApproveSalesDocument $command): int
    {
        $approvedDocument = $this->entityManager->wrapInTransaction(function () use ($command): SalesDocument {
            $document = $this->repository->find($command->documentId);
            if ($document === null) {
                throw new \RuntimeException("Document {$command->documentId} not found");
            }
            if ($document->getStatus() !== SalesDocumentStatus::Draft) {
                throw new \RuntimeException('Document cannot be approved in its current status');
            }

            $document->setStatus(SalesDocumentStatus::Approved);
            $document->setApprovedBy($command->approvedBy);
            $document->setApprovedAt(new \DateTimeImmutable());
            $document->setSellerSnapshot($this->buildSellerSnapshot($document));

            if ($document->getType() !== SalesDocumentType::Quote) {
                return $document;
            }

            $order = new SalesDocument();
            $order->setContractorId($document->getContractorId());
            $order->setCreatedBy($command->approvedBy);
            $order->setType(SalesDocumentType::Order);
            $order->setStatus(SalesDocumentStatus::Approved);
            $order->setApprovedBy($command->approvedBy);
            $order->setApprovedAt(new \DateTimeImmutable());
            $order->setParentQuoteId($document->getId());
            $order->setSellerSnapshot($document->getSellerSnapshot());
            $this->entityManager->persist($order);

            return $order;
        });

        $this->approvalNotifier->documentApproved($approvedDocument);

        return $approvedDocument->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSellerSnapshot(SalesDocument $document): array
    {
        return [
            'contractor_id' => $document->getContractorId(),
            'snapshot_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }
}
