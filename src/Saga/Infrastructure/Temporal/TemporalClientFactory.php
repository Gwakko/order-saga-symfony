<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal;

use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;

/**
 * Builds the Temporal client used by ordinary PHP processes (console, consumers, HTTP). The client talks gRPC straight
 * to the Temporal frontend, so `ext-grpc` must be loaded (`ServiceClient::create()` throws without it). The worker side
 * does not use this class: RoadRunner owns the connection there.
 */
final readonly class TemporalClientFactory
{
    /**
     * @param non-empty-string $address   host:port of the Temporal frontend
     * @param non-empty-string $namespace
     */
    public function __construct(
        private string $address,
        private string $namespace = 'default',
    ) {}

    public function create(): WorkflowClient
    {
        return WorkflowClient::create(
            ServiceClient::create($this->address),
            (new ClientOptions())->withNamespace($this->namespace),
        );
    }
}
