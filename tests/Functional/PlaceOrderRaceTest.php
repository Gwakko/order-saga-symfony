<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel;
use App\Tests\Integration\Support\ForkedRunner;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;

/**
 * EC-02 end to end: two real kernels (own processes, own database connections) receive the same request at the same
 * moment. Whatever the interleaving, exactly one order is created and every answer is consistent with that.
 */
#[Group('M5.2')]
final class PlaceOrderRaceTest extends FunctionalTestCase
{
    #[Group('EC-02')]
    #[Group('concurrency')]
    public function testTwoSimultaneousRequestsWithOneKeyCreateOneOrder(): void
    {
        $product = $this->seedProduct();

        for ($round = 1; $round <= 8; ++$round) {
            $customer = self::uuid();
            $startAt = self::epoch() + 1.0;
            $body = json_encode(self::orderBody($product), JSON_THROW_ON_ERROR);
            $job = static fn(Connection $connection): string => self::sendAt($startAt, $customer, 'race-' . $round, $body);

            /** @var list<array{status: int, replayed: bool, orderId: ?string, code: ?string, retryAfter: ?string}> $answers */
            $answers = array_map(
                static fn(string $raw): array => (array) json_decode($raw, true, 512, JSON_THROW_ON_ERROR),
                ForkedRunner::run($this->connection, [$job, $job]),
            );

            $label = sprintf('round %d: %s', $round, json_encode($answers));
            $fresh = array_values(array_filter($answers, static fn(array $a): bool => $a['status'] === 201 && !$a['replayed']));
            self::assertCount(1, $fresh, $label);

            foreach ($answers as $answer) {
                if ($answer === $fresh[0]) {
                    continue;
                }

                if ($answer['status'] === 201) {
                    self::assertTrue($answer['replayed'], $label);
                    self::assertSame($fresh[0]['orderId'], $answer['orderId'], $label);
                } else {
                    self::assertSame(409, $answer['status'], $label);
                    self::assertSame('IDEMPOTENT_REQUEST_IN_PROGRESS', $answer['code'], $label);
                    self::assertSame('1', $answer['retryAfter'], $label);
                }
            }

            self::assertSame(1, $this->countRows('orders', sprintf("customer_id = '%s'", $customer)), $label);
            self::assertSame(1, $this->countRows('outbox_messages', sprintf("event_type = 'OrderPlaced' AND aggregate_id = '%s'", $fresh[0]['orderId'] ?? '')), $label);
            self::assertSame(1, $this->countRows('idempotency_keys', sprintf("customer_id = '%s' AND status = 'completed'", $customer)), $label);
        }
    }

    private static function epoch(): float
    {
        return (float) (new NativeClock('UTC'))->now()->format('U.u');
    }

    private static function sendAt(float $startAt, string $customer, string $key, string $body): string
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();

        // Warm the container (routing, serializer, validator, database) so that the two requests really overlap.
        $kernel->handle(Request::create('/api/orders', 'GET', [], [], [], ['HTTP_X_CUSTOMER_ID' => $customer]));

        while (self::epoch() < $startAt) {
            usleep(100);
        }

        $request = Request::create('/api/orders', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CUSTOMER_ID' => $customer,
            'HTTP_IDEMPOTENCY_KEY' => $key,
        ], $body);
        $response = $kernel->handle($request);
        $decoded = json_decode((string) $response->getContent(), true);
        $decoded = is_array($decoded) ? $decoded : [];

        return json_encode([
            'status' => $response->getStatusCode(),
            'replayed' => $response->headers->get('Idempotent-Replayed') === 'true',
            'orderId' => $decoded['orderId'] ?? null,
            'code' => $decoded['code'] ?? null,
            'retryAfter' => $response->headers->get('Retry-After'),
        ], JSON_THROW_ON_ERROR);
    }
}
