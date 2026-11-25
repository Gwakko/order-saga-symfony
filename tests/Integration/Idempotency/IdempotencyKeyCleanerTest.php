<?php

declare(strict_types=1);

namespace App\Tests\Integration\Idempotency;

use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Infrastructure\Idempotency\DbalIdempotencyKeyCleaner;
use App\Shared\Infrastructure\Idempotency\DbalIdempotencyStore;
use App\Shared\Infrastructure\Idempotency\IdempotencyKey;
use App\Shared\Infrastructure\Idempotency\IdempotencyOutcome;
use App\Shared\Infrastructure\Schedule\PurgeIdempotencyKeysTick;
use App\Shared\Infrastructure\Schedule\PurgeIdempotencyKeysTickHandler;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * M8a: rows past `expires_at` are removed (EC-06 housekeeping); rows the store would still replay are kept.
 */
#[Group('M8a')]
final class IdempotencyKeyCleanerTest extends IntegrationTestCase
{
    private DbalIdempotencyKeyCleaner $cleaner;
    private DbalIdempotencyStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleaner = new DbalIdempotencyKeyCleaner($this->connection);
        $this->store = new DbalIdempotencyStore($this->connection);
    }

    private function row(string $key, \DateTimeImmutable $startedAt, bool $complete): void
    {
        $customer = CustomerId::fromString(self::uuid());
        $key = IdempotencyKey::fromHeader($key);
        $decision = $this->store->begin($customer, $key, str_repeat('a', 64), 'POST', '/api/orders', $startedAt);
        self::assertSame(IdempotencyOutcome::Started, $decision->outcome);

        if ($complete) {
            $this->store->complete($customer, $key, 201, '{"orderId":"x"}');
        }
    }

    #[Group('T-M8A-24')]
    public function testExpiredRowsGoAndLiveOnesStay(): void
    {
        $now = $this->t0->modify('+48 hours');
        $this->row('expired-done', $this->t0, true);                       // expires t0 + 24 h: past
        $this->row('expired-stuck', $this->t0, false);                     // an abandoned in_progress row: past
        $this->row('live-done', $now->modify('-1 hour'), true);            // expires in 23 h
        $this->row('live-running', $now->modify('-1 second'), false);

        $deleted = $this->cleaner->deleteExpired($now, 100);

        self::assertSame(2, $deleted);
        self::assertSame(2, $this->countRows('idempotency_keys'));
        self::assertSame(0, $this->countRows('idempotency_keys', "key IN ('expired-done', 'expired-stuck')"));
    }

    #[Group('T-M8A-25')]
    public function testBoundaryIsTheSameAsTheStoreUses(): void
    {
        $this->row('edge', $this->t0, true);
        $expiresAt = $this->t0->modify('+24 hours');

        self::assertSame(0, $this->cleaner->deleteExpired($expiresAt->modify('-1 second'), 10), 'one second before expiry the store still replays it');
        self::assertSame(1, $this->cleaner->deleteExpired($expiresAt, 10), '`expires_at <= now` is expired, as in DbalIdempotencyStore::begin');
    }

    #[Group('T-M8A-26')]
    public function testRepeatedRunDeletesNothingMoreAndLimitBoundsARun(): void
    {
        foreach (['a', 'b', 'c'] as $key) {
            $this->row('old-' . $key, $this->t0, true);
        }
        $now = $this->t0->modify('+30 hours');

        self::assertSame(2, $this->cleaner->deleteExpired($now, 2));
        self::assertSame(1, $this->countRows('idempotency_keys'));
        self::assertSame(1, $this->cleaner->deleteExpired($now, 2));
        self::assertSame(0, $this->cleaner->deleteExpired($now, 2), 'a repeat is a no-op');
        self::assertSame(0, $this->countRows('idempotency_keys'));
    }

    #[Group('T-M8A-27')]
    public function testConsoleCommandAndSchedulerTickPurgeWithTheRealClock(): void
    {
        $this->row('very-old', $this->t0, true);          // May 2026, long past 24 h for the real clock
        $this->row('very-old-too', $this->t0, false);
        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException('no kernel')))->find('app:idempotency:purge'));

        self::assertSame(0, $tester->execute(['--limit' => '1']));
        self::assertStringContainsString('deleted 1', $tester->getDisplay());
        self::assertSame(1, $this->countRows('idempotency_keys'));

        $tick = self::getContainer()->get(PurgeIdempotencyKeysTickHandler::class);
        $tick(new PurgeIdempotencyKeysTick(10));
        self::assertSame(0, $this->countRows('idempotency_keys'));

        self::assertSame(2, $tester->execute(['--limit' => '0']), 'an out-of-range limit is refused');
    }
}
