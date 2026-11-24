<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Projection;

/**
 * An event arrived for an order whose `OrderPlaced` is not projected yet. The message is retried later (Messenger retry
 * strategy), by which time the earlier event has been applied.
 */
final class OrderViewNotReady extends \RuntimeException
{
    public function __construct(string $orderId, int $version)
    {
        parent::__construct(sprintf('Order view %s does not exist yet (event version %d); retry later.', $orderId, $version));
    }
}
