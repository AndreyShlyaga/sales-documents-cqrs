<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\SalesDocumentException;
use App\Exception\SalesDocumentNotFound;
use App\Exception\SalesDocumentStatusConflict;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Turns domain exceptions into JSON responses with a meaningful HTTP status.
 *
 * Mapping error kinds to status codes is a cross-cutting concern: every endpoint dispatching a
 * command needs the same translation, so it lives here instead of being copied into each
 * controller action. Anything that is not a known domain error is left untouched and handled by
 * the framework as a 500, which is what an unexpected failure actually is.
 *
 * Messenger wraps whatever a handler throws into a HandlerFailedException, so the original
 * exception has to be unwrapped before it can be recognised.
 */
final class DomainExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $this->unwrap($event->getThrowable());

        if (!$exception instanceof SalesDocumentException) {
            return;
        }

        $event->setResponse(new JsonResponse(
            [
                'error' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ],
            $this->statusFor($exception),
        ));
    }

    private function unwrap(\Throwable $throwable): \Throwable
    {
        while ($throwable instanceof HandlerFailedException && $throwable->getPrevious() !== null) {
            $throwable = $throwable->getPrevious();
        }

        return $throwable;
    }

    private function statusFor(SalesDocumentException $exception): int
    {
        return match (true) {
            $exception instanceof SalesDocumentNotFound => Response::HTTP_NOT_FOUND,
            $exception instanceof SalesDocumentStatusConflict => Response::HTTP_CONFLICT,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }
}
