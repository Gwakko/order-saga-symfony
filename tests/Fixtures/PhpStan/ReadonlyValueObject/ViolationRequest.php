<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Request;

final class PlaceOrderRequest
{
    public function __construct(public readonly string $productId) {}
}

final class NotARequestDto {}
