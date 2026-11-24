<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Controller;

use App\Ordering\Application\Command\CancelOrder\CancelOrderCommand;
use App\Ordering\Application\Command\CancelOrder\CancelOrderOutput;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Infrastructure\Bus\CommandBus;
use App\Shared\Infrastructure\Http\JsonResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/orders/{orderId}/cancel', name: 'api_orders_cancel', methods: ['POST'])]
final readonly class CancelOrderController
{
    public function __construct(private CommandBus $commands, private JsonResponder $responder) {}

    public function __invoke(CustomerId $customerId, string $orderId): Response
    {
        $output = $this->commands->execute(new CancelOrderCommand(strtolower($orderId), $customerId->value), CancelOrderOutput::class);

        return $this->responder->respond($output, Response::HTTP_ACCEPTED);
    }
}
