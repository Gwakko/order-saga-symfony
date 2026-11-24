<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\CustomerIdHeader;
use App\Shared\Infrastructure\Http\CustomerIdValueResolver;
use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Domain\Identifier\OrderId;
use App\Shared\Infrastructure\Http\Exception\CustomerRequired;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

#[Group('M5.1')]
final class CustomerIdHeaderTest extends TestCase
{
    private const string ID = '0190a5c4-0000-7000-8000-000000000001';

    public function testReadsTheHeader(): void
    {
        self::assertSame(self::ID, CustomerIdHeader::fromRequest($this->request(self::ID))->value);
    }

    public function testNormalisesCaseAndSurroundingSpaces(): void
    {
        self::assertSame(self::ID, CustomerIdHeader::fromRequest($this->request(' ' . strtoupper(self::ID) . ' '))->value);
    }

    public function testMissingHeaderIsRefused(): void
    {
        $this->expectException(CustomerRequired::class);

        CustomerIdHeader::fromRequest(new Request());
    }

    public function testNonUuidIsRefused(): void
    {
        $this->expectException(CustomerRequired::class);

        CustomerIdHeader::fromRequest($this->request('customer-1'));
    }

    public function testResolverFillsOnlyCustomerIdArguments(): void
    {
        $resolver = new CustomerIdValueResolver();
        $request = $this->request(self::ID);

        $resolved = iterator_to_array($resolver->resolve($request, new ArgumentMetadata('customerId', CustomerId::class, false, false, null)));
        $other = iterator_to_array($resolver->resolve($request, new ArgumentMetadata('orderId', OrderId::class, false, false, null)));
        $string = iterator_to_array($resolver->resolve($request, new ArgumentMetadata('orderId', 'string', false, false, null)));

        self::assertCount(1, $resolved);
        self::assertSame(self::ID, $resolved[0]->value);
        self::assertSame([], $other);
        self::assertSame([], $string);
    }

    private function request(string $header): Request
    {
        return new Request(server: ['HTTP_X_CUSTOMER_ID' => $header]);
    }
}
