<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Consumer;

use App\Ordering\Application\Port\OrderRefundStarter;
use App\Ordering\Domain\Event\OrderRefundStarted;
use App\Shared\Infrastructure\Inbox\InboxGuard;
use App\Shared\Infrastructure\Outbox\DomainEventCodec;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * `OrderRefundStarted` from the outbox starts `RefundWorkflow` (02_use_cases.md section 1). Same contract as the saga
 * start: the inbox row and the start commit together, a failed start leaves no row so Messenger retries, and a repeated
 * start is absorbed by the starter (EC-61).
 */
#[AsMessageHandler]
final readonly class StartOrderRefundOnOrderRefundStarted
{
    public const string CONSUMER = 'start_order_refund';

    public function __construct(
        private InboxGuard $inbox,
        private DomainEventCodec $codec,
        private OrderRefundStarter $starter,
    ) {}

    public function __invoke(OutboxMessage $message): void
    {
        if ($message->aggregateType !== 'order' || $message->eventType !== 'OrderRefundStarted') {
            return;
        }

        $event = $this->codec->toEvent($message);

        if (!$event instanceof OrderRefundStarted) {
            throw new \UnexpectedValueException(sprintf('%s is not OrderRefundStarted.', $event::class));
        }

        $this->inbox->once(self::CONSUMER, $message->messageId, fn() => $this->starter->start($event->orderId));
    }
}
