<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Controller;

use App\Ordering\Application\Query\ListCustomerOrders\ListCustomerOrdersOutput;
use App\Ordering\Infrastructure\Http\Request\ListCustomerOrdersRequest;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Infrastructure\Bus\QueryBus;
use App\Shared\Infrastructure\Http\JsonResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/orders', name: 'api_orders_list', methods: ['GET'])]
final readonly class ListCustomerOrdersController
{
    public function __construct(private QueryBus $queries, private JsonResponder $responder) {}

    public function __invoke(
        CustomerId $customerId,
        #[MapQueryString]
        ListCustomerOrdersRequest $request = new ListCustomerOrdersRequest(),
    ): Response {
        $output = $this->queries->execute($request->toQuery($customerId), ListCustomerOrdersOutput::class);

        return $this->responder->respond($output);
    }
}
