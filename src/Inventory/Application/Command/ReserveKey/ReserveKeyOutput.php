<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\ReserveKey;

final readonly class ReserveKeyOutput
{
    public function __construct(public string $reservationId, public string $keyId, public \DateTimeImmutable $expiresAt) {}
}
