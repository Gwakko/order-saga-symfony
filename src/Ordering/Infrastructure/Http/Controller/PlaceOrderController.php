<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Controller;

use App\Ordering\Application\Command\PlaceOrder\PlaceOrderOutput;
use App\Ordering\Infrastructure\Http\Request\PlaceOrderRequest;
use App\Shared\Application\Port\IdGenerator;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Infrastructure\Bus\CommandBus;
use App\Shared\Infrastructure\Http\JsonResponder;
use App\Shared\Infrastructure\Idempotency\Idempotent;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

#[Route('/api/orders', name: 'api_orders_place', methods: ['POST'])]
#[Idempotent]
final readonly class PlaceOrderController
{
    public function __construct(private CommandBus $commands, private IdGenerator $ids, private JsonResponder $responder) {}

    public function __invoke(
        CustomerId $customerId,
        #[MapRequestPayload(serializationContext: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false])]
        PlaceOrderRequest $request,
    ): Response {
        // The id is created here, before the call, so a replayed response carries the same id (02_use_cases.md 1.1).
        $output = $this->commands->execute($request->toCommand($this->ids->next(), $customerId), PlaceOrderOutput::class);

        return $this->responder->respond($output, Response::HTTP_CREATED, ['Location' => '/api/orders/' . $output->orderId]);
    }
}
