<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerOutboxPublisher implements OutboxPublisher
{
    public function __construct(private MessageBusInterface $bus) {}

    public function publish(OutboxMessage $message): void
    {
        $this->bus->dispatch($message);
    }
}
