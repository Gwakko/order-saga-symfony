<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Request;

use App\Ordering\Application\Command\PlaceOrder\PlaceOrderCommand;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Identifier\CustomerId;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of `POST /api/orders` (02_use_cases.md section 1.1).
 */
final readonly class PlaceOrderRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $productId,
        #[Assert\Valid]
        public MoneyRequest $expectedPrice,
    ) {}

    public function toCommand(string $orderId, CustomerId $customerId): PlaceOrderCommand
    {
        return new PlaceOrderCommand(
            $orderId,
            $customerId->value,
            strtolower($this->productId),
            $this->expectedPrice->amount,
            Currency::from($this->expectedPrice->currency),
        );
    }
}
