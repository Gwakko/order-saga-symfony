<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

enum ReservationStatus: string
{
    case Active = 'active';
    case Consumed = 'consumed';
    case Released = 'released';
    case Expired = 'expired';

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTargets(), true);
    }

    /**
     * @return list<self>
     */
    private function allowedTargets(): array
    {
        return match ($this) {
            self::Active => [self::Consumed, self::Released, self::Expired],
            self::Consumed, self::Released, self::Expired => [],
        };
    }
}
