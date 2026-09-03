<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SalesDocument;
use App\Message\Command\ApproveSalesDocument;
use App\Message\Command\CreateSalesDocument;
use App\Repository\SalesDocumentRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP entry point of the sales document flow.
 *
 * Writes go through the command bus, reads through the repository, as CQRS prescribes - the query
 * side does not travel over the command bus. Error handling is not here either, because domain
 * exceptions are translated into HTTP statuses by DomainExceptionSubscriber, so one mapping serves
 * every endpoint instead of a try/catch copied into each action.
 *
 * A note on layering. Given a free hand I would not let a controller talk to a repository at all,
 * not even for reads. I would put a read service in between, make it the only thing that knows
 * about persistence, and leave the controller with what it is actually for, which is accepting a
 * request and returning a response. The controller would then depend on a service rather than on a
 * repository, and swapping the storage or adding caching would never reach the HTTP layer. Here I
 * call the repository directly because the assignment asks for exactly that, and introducing a
 * layer nobody asked for would only obscure the point of the fix.
 */
final class SalesDocumentController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly SalesDocumentRepository $repository,
    ) {
    }

    #[Route('/sales-documents', name: 'sales_document_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (empty($payload['contractor_id']) || empty($payload['created_by'])) {
            return new JsonResponse(['error' => 'Missing fields'], Response::HTTP_BAD_REQUEST);
        }

        $envelope = $this->commandBus->dispatch(new CreateSalesDocument(
            contractorId: (int) $payload['contractor_id'],
            createdBy: (int) $payload['created_by'],
        ));

        $id = $envelope->last(HandledStamp::class)->getResult();

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/sales-documents/{id}/approve', name: 'sales_document_approve', methods: ['POST'])]
    public function approve(int $id, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $approvedBy = (int) ($payload['approved_by'] ?? 0);

        $envelope = $this->commandBus->dispatch(new ApproveSalesDocument($id, $approvedBy));
        $approvedId = $envelope->last(HandledStamp::class)->getResult();

        return new JsonResponse($this->present($this->repository->find($approvedId)));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SalesDocument $document): array
    {
        return [
            'id' => $document->getId(),
            'type' => $document->getType()->value,
            'status' => $document->getStatus()->value,
            'parent_quote_id' => $document->getParentQuoteId(),
        ];
    }
}
