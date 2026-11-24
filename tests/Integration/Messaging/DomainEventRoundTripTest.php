<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Infrastructure\Outbox\DomainEventCodec;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Support\RecordingOutboxPublisher;
use App\Tests\Support\DomainEventSamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * JSON is the format of the outbox row and of the Messenger transport. Every domain event survives
 * event -> OutboxMessage -> JSON -> OutboxMessage -> event unchanged (value equality), money stays integer and a key
 * code travels only masked (INV-17).
 */
#[Group('M4.6')]
#[Group('outbox-json')]
final class DomainEventRoundTripTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{DomainEvent}>
     */
    public static function events(): iterable
    {
        foreach (DomainEventSamples::all() as $name => $event) {
            yield $name => [$event];
        }
    }

    public function testSamplesCoverEveryConcreteDomainEvent(): void
    {
        $covered = array_values(array_unique(array_map(static fn(DomainEvent $event): string => $event::class, DomainEventSamples::all())));
        sort($covered);

        self::assertSame(DomainEventSamples::concreteEventClasses(), $covered, 'a new domain event needs a sample, a normalizer and a denormalizer');
        self::assertCount(21, $covered);
    }

    #[DataProvider('events')]
    public function testEventSurvivesJsonRoundTrip(DomainEvent $event): void
    {
        $codec = $this->codec();
        $serializer = $this->transportSerializer();

        $encoded = $serializer->encode(new Envelope($codec->toMessage($event)));
        self::assertJson($encoded['body']);
        self::assertSame('application/json', ($encoded['headers'] ?? [])['Content-Type'] ?? null);

        $received = $serializer->decode($encoded)->getMessage();
        self::assertInstanceOf(OutboxMessage::class, $received);

        $back = $codec->toEvent($received);

        self::assertEquals($event, $back);
        self::assertSame($event::class, $back::class);
        self::assertSame($event->occurredAt()->format('Y-m-d\TH:i:s.uP'), $back->occurredAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'), 'microseconds are kept');
    }

    #[DataProvider('events')]
    public function testJsonHasIntegerMoneyAndNoKeyCode(DomainEvent $event): void
    {
        $encoded = $this->transportSerializer()->encode(new Envelope($this->codec()->toMessage($event)));
        $decoded = json_decode($encoded['body'], true, 32, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertNoFloats($decoded);
        self::assertStringNotContainsString(DomainEventSamples::SECRET_KEY, $encoded['body'] . json_encode($encoded['headers'] ?? [], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('7391', str_replace('XXXX-...-7391', '', $encoded['body']), 'only the masked form XXXX-...-<last 4> may appear');

        $payload = $decoded['payload'];
        self::assertIsArray($payload);

        foreach (['price_amount', 'amount', 'charged_amount'] as $field) {
            if (array_key_exists($field, $payload)) {
                self::assertIsInt($payload[$field], $field . ' is an integer in minor units');
            }
        }
    }

    public function testRowWrittenByTheOutboxWriterRebuildsTheSameEventsThroughTheRelay(): void
    {
        $samples = array_values(DomainEventSamples::all());
        $this->infra->outbox->append($samples);

        $publisher = new RecordingOutboxPublisher();
        self::assertSame(count($samples), $this->infra->relay($publisher)->publishBatch(100));

        $codec = $this->codec();
        $rebuilt = [];

        foreach ($publisher->sent as $message) {
            $rebuilt[$message->messageId . '#' . $message->aggregateVersion] = $codec->toEvent($message);
        }

        foreach ($samples as $event) {
            self::assertEquals($event, $rebuilt[$event->eventId()->value . '#' . $event->aggregateVersion()] ?? null, $event::class . ' v' . $event->aggregateVersion());
        }

        $payloads = $this->connection->fetchFirstColumn('SELECT payload::text FROM outbox_messages');
        self::assertCount(count($samples), $payloads);

        foreach ($payloads as $payload) {
            self::assertIsString($payload);
            self::assertStringNotContainsString(DomainEventSamples::SECRET_KEY, $payload);
        }
    }

    public function testEventWithoutPayloadIsStoredAsEmptyJsonObject(): void
    {
        $cancelled = null;

        foreach (DomainEventSamples::all() as $event) {
            if ($event::class === \App\Ordering\Domain\Event\OrderCancelled::class) {
                $cancelled = $event;
            }
        }

        self::assertNotNull($cancelled);
        $this->infra->outbox->append([$cancelled]);

        self::assertSame('{}', $this->connection->fetchOne('SELECT payload::text FROM outbox_messages'));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function assertNoFloats(array $data): void
    {
        foreach ($data as $value) {
            self::assertIsNotFloat($value);

            if (is_array($value)) {
                self::assertNoFloats($value);
            }
        }
    }

    private function codec(): DomainEventCodec
    {
        return self::getContainer()->get(DomainEventCodec::class);
    }

    private function transportSerializer(): SerializerInterface
    {
        return self::getContainer()->get('messenger.transport.symfony_serializer');
    }
}
