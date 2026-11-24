<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal;

use Carbon\CarbonInterval;
use Temporal\Client\ClientOptions;
use Temporal\Client\Common\RpcRetryOptions;
use Temporal\Client\GRPC\Context;
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
     * @param int<1, max>      $callTimeoutSeconds deadline of one call (SDK default: none)
     * @param int<1, max>      $maxAttempts        attempts at an unavailable server per call (SDK default: unlimited)
     */
    public function __construct(
        private string $address,
        private string $namespace = 'default',
        private int $callTimeoutSeconds = 10,
        private int $maxAttempts = 3,
    ) {}

    public function create(): WorkflowClient
    {
        // Without a retry limit the SDK client retries an unreachable server forever, and a consumer that never fails is never retried by Messenger (EC-58).
        $context = Context::default()
            ->withTimeout($this->callTimeoutSeconds)
            ->withRetryOptions(
                RpcRetryOptions::new()
                    ->withMaximumAttempts($this->maxAttempts)
                    ->withInitialInterval(CarbonInterval::milliseconds(200)),
            );

        return WorkflowClient::create(
            ServiceClient::create($this->address)->withContext($context),
            (new ClientOptions())->withNamespace($this->namespace),
        );
    }
}
