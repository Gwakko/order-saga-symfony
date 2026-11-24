<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

interface OutboxPublisher
{
    /**
     * Hands the message to the transport. Throws when it was not accepted.
     */
    public function publish(OutboxMessage $message): void;
}
