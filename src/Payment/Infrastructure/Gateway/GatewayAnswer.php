<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Gateway;

/**
 * A fully read HTTP answer: status and the JSON object of the body (null when the body is not JSON or is cut short).
 */
final readonly class GatewayAnswer
{
    /**
     * @param array<string, mixed>|null $json
     */
    public function __construct(public int $status, public ?array $json) {}
}
