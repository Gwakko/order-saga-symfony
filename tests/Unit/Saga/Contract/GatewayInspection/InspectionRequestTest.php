<?php

declare(strict_types=1);

namespace App\Tests\Unit\Saga\Contract\GatewayInspection;

use App\Saga\Infrastructure\Temporal\Contract\GatewayInspection\InspectionContractViolation;
use App\Saga\Infrastructure\Temporal\Contract\GatewayInspection\InspectionRequest;
use App\Shared\Domain\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Temporal\DataConverter\DataConverter;

#[Group('M9.1')]
final class InspectionRequestTest extends TestCase
{
    #[Group('T-M9-01')]
    public function testToArrayEqualsTheGoldenInput(): void
    {
        self::assertEquals(GoldenFiles::json('input.v1.json'), $this->golden()->toArray());
    }

    #[Group('T-M9-01')]
    public function testAmountIsAnIntegerAndTheVersionIsOne(): void
    {
        $array = $this->golden()->toArray();

        self::assertSame(1, $array['contractVersion']);
        self::assertSame(1999, $array['expected']['amount']);
        self::assertSame('EUR', $array['expected']['currency']);
    }

    /** `PrepareChargeInspection` answers the idempotency key (the payment id) and the stored money; `from` builds the golden request. */
    #[Group('T-M9-01')]
    public function testFromBuildsTheRequestFromThePrepareAnswer(): void
    {
        $request = InspectionRequest::from(
            ['inspect' => true, 'paymentId' => '0190a5c4-0000-7000-8000-0000000000a1', 'amount' => 1999, 'currency' => 'EUR'],
            '0190a5c4-0000-7000-8000-000000000020',
        );

        self::assertEquals(GoldenFiles::json('input.v1.json'), $request->toArray());
    }

    /** @param array<mixed> $prepared */
    #[Group('T-M9-01')]
    #[DataProvider('brokenPrepareAnswers')]
    public function testFromRejectsAnAnswerThatIsNotUsable(array $prepared): void
    {
        $this->expectException(InspectionContractViolation::class);

        InspectionRequest::from($prepared, '0190a5c4-0000-7000-8000-000000000020');
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function brokenPrepareAnswers(): iterable
    {
        $ok = ['inspect' => true, 'paymentId' => '0190a5c4-0000-7000-8000-0000000000a1', 'amount' => 1999, 'currency' => 'EUR'];

        yield 'skip answer' => [['inspect' => false, 'paymentId' => null, 'amount' => null, 'currency' => null]];
        yield 'no payment id' => [['paymentId' => null] + $ok];
        yield 'float amount' => [['amount' => 19.99] + $ok];
        yield 'string amount' => [['amount' => '1999'] + $ok];
        yield 'zero amount' => [['amount' => 0] + $ok];
        yield 'unknown currency' => [['currency' => 'XYZ'] + $ok];
        yield 'lowercase currency' => [['currency' => 'eur'] + $ok];
        yield 'key with a slash' => [['paymentId' => 'a/b'] + $ok];
    }

    /** @param array{string, string, int} $args */
    #[Group('T-M9-01')]
    #[DataProvider('invalidRequests')]
    public function testRequestsOutsideTheSchemaAreRejected(array $args): void
    {
        $this->expectException(InspectionContractViolation::class);

        new InspectionRequest($args[0], $args[1], $args[2], Currency::EUR);
    }

    /** @return iterable<string, array{array{string, string, int}}> */
    public static function invalidRequests(): iterable
    {
        $order = '0190a5c4-0000-7000-8000-000000000020';
        $key = '0190a5c4-0000-7000-8000-0000000000a1';

        yield 'order id is not a UUID' => [['order-1', $key, 1999]];
        yield 'empty key' => [[$order, '', 1999]];
        yield 'key with a slash' => [[$order, 'a/b', 1999]];
        yield 'key longer than 64' => [[$order, str_repeat('a', 65), 1999]];
        yield 'key with a trailing newline' => [[$order, "abc\n", 1999]];
        yield 'zero amount' => [[$order, $key, 0]];
        yield 'negative amount' => [[$order, $key, -1]];
        yield 'amount above the maximum' => [[$order, $key, 100_000_001]];
    }

    #[Group('T-M9-01')]
    public function testBoundaryAmountsAreAccepted(): void
    {
        $order = '0190a5c4-0000-7000-8000-000000000020';

        self::assertSame(1, (new InspectionRequest($order, 'k', 1, Currency::UAH))->amount);
        self::assertSame(100_000_000, (new InspectionRequest($order, str_repeat('a', 64), 100_000_000, Currency::PLN))->amount);
    }

    #[Group('T-M9-03')]
    public function testDefaultDataConverterEncodesTheRequestAsJsonPlain(): void
    {
        $payload = DataConverter::createDefault()->toPayload($this->golden()->toArray());

        $metadata = [];

        foreach ($payload->getMetadata() as $key => $value) {
            $metadata[is_string($key) ? $key : ""] = $value;
        }

        self::assertSame('json/plain', $metadata['encoding'] ?? null);
        self::assertEquals(GoldenFiles::json('input.v1.json'), json_decode($payload->getData(), true, 512, JSON_THROW_ON_ERROR));
    }

    #[Group('T-M9-03')]
    public function testThePayloadDecodesBackToTheGoldenInput(): void
    {
        $converter = DataConverter::createDefault();
        $payload = $converter->toPayload($this->golden()->toArray());

        self::assertEquals(GoldenFiles::json('input.v1.json'), $converter->fromPayload($payload, 'array'));
    }

    private function golden(): InspectionRequest
    {
        return new InspectionRequest('0190a5c4-0000-7000-8000-000000000020', '0190a5c4-0000-7000-8000-0000000000a1', 1999, Currency::EUR);
    }
}
