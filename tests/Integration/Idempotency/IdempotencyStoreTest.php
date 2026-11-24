<?php

declare(strict_types=1);

namespace App\Tests\Integration\Idempotency;

use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Infrastructure\Idempotency\DbalIdempotencyStore;
use App\Shared\Infrastructure\Idempotency\IdempotencyKey;
use App\Shared\Infrastructure\Idempotency\IdempotencyOutcome;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('M5.2')]
final class IdempotencyStoreTest extends IntegrationTestCase
{
    private const string HASH_A = 'a3f5c2d1e4b6978a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f708192a3b4c5d6';
    private const string HASH_B = 'b3f5c2d1e4b6978a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f708192a3b4c5d6';

    private DbalIdempotencyStore $store;
    private CustomerId $customer;
    private IdempotencyKey $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new DbalIdempotencyStore($this->connection);
        $this->customer = CustomerId::fromString(self::uuid());
        $this->key = IdempotencyKey::fromHeader('key-1');
    }

    public function testFirstRequestStartsAndIsRecordedAsInProgress(): void
    {
        $decision = $this->begin(self::HASH_A, $this->t0);

        self::assertSame(IdempotencyOutcome::Started, $decision->outcome);
        self::assertSame(1, $this->countRows('idempotency_keys', "status = 'in_progress' AND response_status IS NULL AND response_body IS NULL"));
        self::assertSame(
            $this->t0->modify('+24 hours')->format('Y-m-d H:i:s'),
            $this->connection->fetchOne("SELECT to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM idempotency_keys"),
            'TTL is 24 hours',
        );
    }

    public function testSecondRequestWhileInProgressWaits(): void
    {
        $this->begin(self::HASH_A, $this->t0);

        self::assertSame(IdempotencyOutcome::InProgress, $this->begin(self::HASH_A, $this->t0->modify('+5 seconds'))->outcome);
    }

    public function testCompletedAnswerIsReplayedWithStatusAndBody(): void
    {
        $this->begin(self::HASH_A, $this->t0);
        $this->store->complete($this->customer, $this->key, 409, '{"code":"PRICE_CHANGED","status":409}');

        $decision = $this->begin(self::HASH_A, $this->t0->modify('+1 hour'));

        self::assertSame(IdempotencyOutcome::Replay, $decision->outcome);
        self::assertSame(409, $decision->responseStatus);
        self::assertSame('{"code": "PRICE_CHANGED", "status": 409}', $decision->responseBody, 'jsonb text form');
    }

    public function testOtherRequestUnderTheSameKeyIsRejectedBeforeAndAfterCompletion(): void
    {
        $this->begin(self::HASH_A, $this->t0);
        self::assertSame(IdempotencyOutcome::KeyReused, $this->begin(self::HASH_B, $this->t0)->outcome);

        $this->store->complete($this->customer, $this->key, 201, '{}');
        self::assertSame(IdempotencyOutcome::KeyReused, $this->begin(self::HASH_B, $this->t0)->outcome);
    }

    /**
     * EC-06: after 24 hours the key is a new request, even with a different body.
     */
    #[Group('T-I-02')]
    public function testExpiredKeyIsReplaced(): void
    {
        $this->begin(self::HASH_A, $this->t0);
        $this->store->complete($this->customer, $this->key, 201, '{"orderId":"first"}');

        $justBefore = $this->begin(self::HASH_A, $this->t0->modify('+24 hours -1 second'));
        self::assertSame(IdempotencyOutcome::Replay, $justBefore->outcome, 'still inside the TTL');

        $later = $this->t0->modify('+24 hours +1 second');
        $decision = $this->begin(self::HASH_B, $later);

        self::assertSame(IdempotencyOutcome::Started, $decision->outcome);
        self::assertSame(1, $this->countRows('idempotency_keys'));
        self::assertSame(1, $this->countRows('idempotency_keys', "status = 'in_progress' AND response_body IS NULL"), 'the old answer is gone');
    }

    public function testExpiryIsInclusive(): void
    {
        $this->begin(self::HASH_A, $this->t0);
        $this->store->complete($this->customer, $this->key, 201, '{}');

        self::assertSame(IdempotencyOutcome::Started, $this->begin(self::HASH_A, $this->t0->modify('+24 hours'))->outcome, 'expires_at <= now means expired');
    }

    /**
     * EC-07b: a crashed process leaves `in_progress`; after 60 seconds the key is free.
     */
    #[Group('T-I-03')]
    public function testStaleInProgressIsReplaced(): void
    {
        $this->begin(self::HASH_A, $this->t0);

        self::assertSame(IdempotencyOutcome::InProgress, $this->begin(self::HASH_A, $this->t0->modify('+59 seconds'))->outcome);
        self::assertSame(IdempotencyOutcome::Started, $this->begin(self::HASH_A, $this->t0->modify('+60 seconds'))->outcome);
        self::assertSame(1, $this->countRows('idempotency_keys'));
        self::assertSame(
            $this->t0->modify('+60 seconds')->format('Y-m-d H:i:s'),
            $this->connection->fetchOne("SELECT to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM idempotency_keys"),
            'the row belongs to the new attempt',
        );
    }

    public function testStaleInProgressIsReplacedEvenWithAnotherBody(): void
    {
        $this->begin(self::HASH_A, $this->t0);

        self::assertSame(IdempotencyOutcome::Started, $this->begin(self::HASH_B, $this->t0->modify('+61 seconds'))->outcome);
    }

    public function testCompletedRowIsNeverTreatedAsStale(): void
    {
        $this->begin(self::HASH_A, $this->t0);
        $this->store->complete($this->customer, $this->key, 201, '{}');

        self::assertSame(IdempotencyOutcome::Replay, $this->begin(self::HASH_A, $this->t0->modify('+2 hours'))->outcome);
    }

    public function testReleaseForgetsTheKey(): void
    {
        $this->begin(self::HASH_A, $this->t0);
        $this->store->release($this->customer, $this->key);

        self::assertSame(0, $this->countRows('idempotency_keys'));
        self::assertSame(IdempotencyOutcome::Started, $this->begin(self::HASH_B, $this->t0)->outcome, 'a retry may even differ');
    }

    public function testReleaseDoesNotRemoveACompletedAnswer(): void
    {
        $this->begin(self::HASH_A, $this->t0);
        $this->store->complete($this->customer, $this->key, 201, '{}');
        $this->store->release($this->customer, $this->key);

        self::assertSame(1, $this->countRows('idempotency_keys', "status = 'completed'"));
    }

    public function testCompleteOfAForgottenKeyDoesNothing(): void
    {
        $this->store->complete($this->customer, $this->key, 201, '{}');

        self::assertSame(0, $this->countRows('idempotency_keys'));
    }

    /**
     * EC-05: the key belongs to the customer.
     */
    public function testKeysOfDifferentCustomersAreIndependent(): void
    {
        $other = CustomerId::fromString(self::uuid());
        $this->begin(self::HASH_A, $this->t0);

        $decision = $this->store->begin($other, $this->key, self::HASH_B, 'POST', '/api/orders', $this->t0);

        self::assertSame(IdempotencyOutcome::Started, $decision->outcome);
        self::assertSame(2, $this->countRows('idempotency_keys'));
    }

    public function testMicrosecondsOfTheStaleRowDoNotConfuseTheConditionalDelete(): void
    {
        $at = new \DateTimeImmutable('2026-05-01T10:00:00.123456Z');
        $this->begin(self::HASH_A, $at);

        self::assertSame(IdempotencyOutcome::Started, $this->begin(self::HASH_A, $at->modify('+61 seconds'))->outcome);
    }

    private function begin(string $hash, \DateTimeImmutable $now): \App\Shared\Infrastructure\Idempotency\IdempotencyDecision
    {
        return $this->store->begin($this->customer, $this->key, $hash, 'POST', '/api/orders', $now);
    }
}
