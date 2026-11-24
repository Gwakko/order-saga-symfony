<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Controller;

use App\Ordering\Application\Query\GetOrder\GetOrderQuery;
use App\Ordering\Application\Query\GetOrder\OrderDetailsOutput;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Infrastructure\Bus\QueryBus;
use App\Shared\Infrastructure\Http\JsonResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/orders/{orderId}', name: 'api_orders_get', methods: ['GET'])]
final readonly class GetOrderController
{
    public function __construct(private QueryBus $queries, private JsonResponder $responder) {}

    public function __invoke(CustomerId $customerId, string $orderId): Response
    {
        $output = $this->queries->execute(new GetOrderQuery(strtolower($orderId), $customerId->value), OrderDetailsOutput::class);

        return $this->responder->respond($output);
    }
}
