<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Domain\Event\DomainEvent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Turns the events of one module into outbox rows. Implemented per module (the domain stays free of serialisation
 * attributes, 00_overview.md section 8 rule 7).
 */
#[AutoconfigureTag('app.outbox.event_normalizer')]
interface EventNormalizer
{
    public function supports(DomainEvent $event): bool;

    public function normalize(DomainEvent $event): NormalizedEvent;
}
