<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Controller;

use App\Ordering\Application\Command\RequestRefund\RequestRefundCommand;
use App\Ordering\Application\Command\RequestRefund\RequestRefundOutput;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Infrastructure\Bus\CommandBus;
use App\Shared\Infrastructure\Http\JsonResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/orders/{orderId}/refund', name: 'api_orders_refund', methods: ['POST'])]
final readonly class RequestRefundController
{
    public function __construct(private CommandBus $commands, private JsonResponder $responder) {}

    public function __invoke(CustomerId $customerId, string $orderId): Response
    {
        $output = $this->commands->execute(new RequestRefundCommand(strtolower($orderId), $customerId->value), RequestRefundOutput::class);

        return $this->responder->respond($output, Response::HTTP_ACCEPTED);
    }
}
