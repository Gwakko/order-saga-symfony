<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * EC-53, INV-11: a message id is applied at most once per consumer.
 */
#[Group('M4.3')]
final class InboxTest extends IntegrationTestCase
{
    #[Group('T-I-33')]
    public function testDuplicateMessageIgnored(): void
    {
        $inbox = $this->infra->inbox();
        $messageId = self::uuid();
        $applied = 0;

        $first = $inbox->once('projection', $messageId, function () use (&$applied): void {
            ++$applied;
        });
        $second = $inbox->once('projection', $messageId, function () use (&$applied): void {
            ++$applied;
        });

        self::assertTrue($first);
        self::assertFalse($second);
        self::assertSame(1, $applied);
        self::assertSame(1, $this->countRows('inbox_messages'));
    }

    public function testTwoConsumersEachApplyTheSameMessage(): void
    {
        $inbox = $this->infra->inbox();
        $messageId = self::uuid();
        $applied = [];

        foreach (['projection', 'start-saga', 'projection', 'start-saga'] as $consumer) {
            $inbox->once($consumer, $messageId, function () use (&$applied, $consumer): void {
                $applied[] = $consumer;
            });
        }

        self::assertSame(['projection', 'start-saga'], $applied);
    }

    public function testFailingHandlerLeavesNoInboxRowSoTheRetryRuns(): void
    {
        $inbox = $this->infra->inbox();
        $messageId = self::uuid();

        try {
            $inbox->once('projection', $messageId, static function (): void {
                throw new \RuntimeException('handler failed');
            });
            self::fail('The failure must propagate to Messenger.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->countRows('inbox_messages'));
        }

        $ran = false;
        self::assertTrue($inbox->once('projection', $messageId, function () use (&$ran): void {
            $ran = true;
        }));
        self::assertTrue($ran);
    }

    public function testHandlerEffectsAndInboxRowCommitTogether(): void
    {
        $inbox = $this->infra->inbox();
        $order = $this->placeOrder();

        $inbox->once('projection', self::uuid(), fn() => $this->infra->orders->save($order));

        self::assertSame(1, $this->countRows('inbox_messages'));
        self::assertSame(1, $this->countRows('orders'));
    }
}
