<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Id;

use App\Shared\Application\Port\IdGenerator;
use Symfony\Component\Uid\Uuid;

final readonly class SymfonyUuidGenerator implements IdGenerator
{
    public function next(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
