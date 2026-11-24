<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\FileOutboxPublisher;
use App\Tests\Integration\Support\ForkedRunner;
use App\Tests\Integration\Support\Infra;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

/**
 * EC-56: parallel relays take disjoint rows (`FOR UPDATE SKIP LOCKED`), every message is sent exactly once.
 */
#[Group('M4.3')]
#[Group('T-I-35')]
#[Group('concurrency')]
final class OutboxRelayConcurrencyTest extends IntegrationTestCase
{
    public function testThreeRelaysSendEveryMessageOnce(): void
    {
        for ($round = 1; $round <= 10; ++$round) {
            $this->connection->executeStatement('TRUNCATE outbox_messages');
            $ids = $this->seedOutbox(90);
            $sent = tempnam(sys_get_temp_dir(), 'relay');
            self::assertIsString($sent);

            $relay = static function (Connection $connection) use ($sent): string {
                $relay = (new Infra($connection))->relay(new FileOutboxPublisher($sent, 300), new MockClock('2026-05-01T12:00:00Z'));
                $total = 0;

                while (($published = $relay->publishBatch(10)) > 0) {
                    $total += $published;
                }

                return (string) $total;
            };

            $results = ForkedRunner::run($this->connection, [$relay, $relay, $relay]);

            // A relay that finds every remaining row locked by the others simply stops; the rest is drained here.
            $this->infra->relay(new FileOutboxPublisher($sent), new MockClock('2026-05-01T12:00:00Z'))->publishBatch(100);

            $lines = file($sent, FILE_IGNORE_NEW_LINES);
            unlink($sent);
            self::assertIsArray($lines);

            $sorted = $lines;
            sort($sorted);
            self::assertSame($ids, $sorted, sprintf('round %d: every message exactly once (%s)', $round, implode(',', $results)));
            self::assertSame(0, $this->countRows('outbox_messages', 'published_at IS NULL'));
            self::assertCount(3, array_filter($results, static fn(string $r): bool => ctype_digit($r)), implode(' | ', $results));
        }
    }
}
