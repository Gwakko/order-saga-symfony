<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

enum KeyStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case Revoked = 'revoked';

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
            self::Available => [self::Reserved, self::Revoked],
            self::Reserved => [self::Available, self::Sold, self::Revoked],
            self::Sold => [self::Revoked],
            self::Revoked => [],
        };
    }
}
