<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\SalesDocument;
use Psr\Log\LoggerInterface;

/**
 * Notifies both parties about an approved document.
 *
 * Runs after the approval transaction has been committed, therefore a failure of the
 * notification channel must never reach the caller: the approval is already durable, and
 * reporting it as failed would make the client retry an operation that did succeed. Channel
 * failures are logged instead, and one failing recipient does not cancel the other.
 */
final class ApprovalNotifier
{
    public function __construct(
        private readonly NotifierPort $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function documentApproved(SalesDocument $document): void
    {
        $message = "Document #{$document->getId()} has been approved";

        $this->notifyQuietly($document->getCreatedBy(), $message);
        $this->notifyQuietly($document->getContractorId(), $message);
    }

    private function notifyQuietly(int $userId, string $message): void
    {
        try {
            $this->notifier->notify($userId, $message);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to notify user {userId} about an approved document', [
                'userId' => $userId,
                'exception' => $e,
            ]);
        }
    }
}
