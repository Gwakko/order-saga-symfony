<?php

declare(strict_types=1);

namespace GatewayEmulator;

enum ModeKind: string
{
    case Ok = 'ok';
    case Decline = 'decline';
    case Delay = 'delay';
    case Http500 = 'http500';
    case Http503 = 'http503';
    case AcceptThen502 = 'accept-then-502';
    case TruncatedJson = 'truncated-json';
    case NoChargeId = 'no-charge-id';
    case WrongCurrency = 'wrong-currency';
    case WrongAmount = 'wrong-amount';
    case DropConnection = 'drop-connection';
    case UnknownStatus = 'unknown-status';
    case LateSuccess = 'late-success';

    public function takesParameter(): bool
    {
        return match ($this) {
            self::Delay, self::Http503, self::LateSuccess => true,
            default => false,
        };
    }
}
