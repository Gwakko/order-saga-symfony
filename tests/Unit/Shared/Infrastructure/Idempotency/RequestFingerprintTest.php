<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Idempotency;

use App\Shared\Infrastructure\Idempotency\RequestFingerprint;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('M5.2')]
final class RequestFingerprintTest extends TestCase
{
    public function testIsSha256Hex(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', RequestFingerprint::of('POST', '/api/orders', '{}'));
    }

    public function testObjectKeyOrderAndWhitespaceDoNotMatter(): void
    {
        $a = RequestFingerprint::of('POST', '/api/orders', '{"a":1,"b":{"x":[1,2],"y":"z"}}');
        $b = RequestFingerprint::of('POST', '/api/orders', "{ \"b\": {\"y\": \"z\", \"x\": [1, 2]},\n \"a\": 1 }");

        self::assertSame($a, $b);
    }

    public function testArrayOrderMatters(): void
    {
        self::assertNotSame(
            RequestFingerprint::of('POST', '/p', '{"a":[1,2]}'),
            RequestFingerprint::of('POST', '/p', '{"a":[2,1]}'),
        );
    }

    public function testDifferentValuesMethodsOrPathsDiffer(): void
    {
        $base = RequestFingerprint::of('POST', '/api/orders', '{"a":1}');

        self::assertNotSame($base, RequestFingerprint::of('POST', '/api/orders', '{"a":2}'));
        self::assertNotSame($base, RequestFingerprint::of('PUT', '/api/orders', '{"a":1}'));
        self::assertNotSame($base, RequestFingerprint::of('POST', '/api/orders/x', '{"a":1}'));
    }

    public function testTypeDifferencesMatter(): void
    {
        self::assertNotSame(RequestFingerprint::of('POST', '/p', '{"a":1}'), RequestFingerprint::of('POST', '/p', '{"a":"1"}'));
        self::assertNotSame(RequestFingerprint::of('POST', '/p', '{"a":1}'), RequestFingerprint::of('POST', '/p', '{"a":1.0}'));
    }

    public function testBodyThatIsNotJsonIsHashedAsItCame(): void
    {
        self::assertNotSame(RequestFingerprint::of('POST', '/p', '{"a":'), RequestFingerprint::of('POST', '/p', '{"b":'));
        self::assertSame(RequestFingerprint::of('POST', '/p', '{"a":'), RequestFingerprint::of('POST', '/p', '{"a":'));
    }

    public function testEmptyObjectAndEmptyArrayStayDistinct(): void
    {
        self::assertNotSame(RequestFingerprint::of('POST', '/p', '{}'), RequestFingerprint::of('POST', '/p', '[]'));
    }
}
