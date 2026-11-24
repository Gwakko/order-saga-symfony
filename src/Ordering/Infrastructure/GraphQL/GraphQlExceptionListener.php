<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\GraphQL;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Failures outside query execution (missing `X-Customer-Id`, malformed body, wrong method) still answer in GraphQL's
 * error shape with the same `extensions.code` values as the REST problems.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
final readonly class GraphQlExceptionListener
{
    public function __construct(private GraphQlErrorFormatter $errors) {}

    public function __invoke(ExceptionEvent $event): void
    {
        if ($event->getRequest()->getPathInfo() !== '/graphql') {
            return;
        }

        $described = $this->errors->describe($event->getThrowable());

        $event->setResponse(new JsonResponse(
            ['errors' => [['message' => $described['message'], 'extensions' => ['code' => $described['code'], 'status' => $described['status']]]]],
            $described['status'],
        ));
    }
}
