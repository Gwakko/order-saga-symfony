<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Consumer;

use App\Ordering\Application\Port\OrderSagaStarter;
use App\Ordering\Domain\Event\OrderPlaced;
use App\Shared\Infrastructure\Inbox\InboxGuard;
use App\Shared\Infrastructure\Outbox\DomainEventCodec;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * `OrderPlaced` from the outbox starts the fulfilment saga (02_use_cases.md section 2). The inbox row and the start
 * commit together, and a failed start leaves no row, so Messenger's retry starts again (EC-58); a repeated delivery of
 * the same message is dropped by the inbox (EC-11, INV-11), and a start that races is absorbed by the starter (INV-16).
 */
#[AsMessageHandler]
final readonly class StartOrderSagaOnOrderPlaced
{
    public const string CONSUMER = 'start_order_saga';

    public function __construct(
        private InboxGuard $inbox,
        private DomainEventCodec $codec,
        private OrderSagaStarter $starter,
    ) {}

    public function __invoke(OutboxMessage $message): void
    {
        if ($message->aggregateType !== 'order' || $message->eventType !== 'OrderPlaced') {
            return;
        }

        $event = $this->codec->toEvent($message);

        if (!$event instanceof OrderPlaced) {
            throw new \UnexpectedValueException(sprintf('%s is not OrderPlaced.', $event::class));
        }

        $this->inbox->once(self::CONSUMER, $message->messageId, fn() => $this->starter->start($event->orderId));
    }
}
