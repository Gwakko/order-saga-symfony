<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class QueryBus extends UseCaseBus
{
    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $bus)
    {
        parent::__construct($bus);
    }
}
