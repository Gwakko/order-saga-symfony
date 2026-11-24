<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Typed access to a synchronous use case bus. The handler's own exception is rethrown unwrapped, so callers and the
 * error listener see the domain error and not Messenger's wrapper. (`getWrappedExceptions()` is keyed by handler name,
 * so the first one is taken with `array_values`; before M6.2 `[0]` never matched and the wrapper leaked through.)
 */
abstract readonly class UseCaseBus
{
    protected function __construct(private MessageBusInterface $bus) {}

    /**
     * @template T of object
     *
     * @param class-string<T> $outputClass
     *
     * @return T
     */
    final public function execute(object $message, string $outputClass): object
    {
        try {
            $envelope = $this->bus->dispatch($message);
        } catch (HandlerFailedException $e) {
            throw array_values($e->getWrappedExceptions())[0] ?? $e;
        }

        $result = $envelope->last(HandledStamp::class)?->getResult();

        return $result instanceof $outputClass
            ? $result
            : throw new \LogicException(sprintf('Handler of %s did not return %s.', $message::class, $outputClass));
    }

    /**
     * For use cases without an Output (the handler returns nothing). Same unwrapping of the handler's exception.
     */
    final public function dispatch(object $message): void
    {
        try {
            $this->bus->dispatch($message);
        } catch (HandlerFailedException $e) {
            throw array_values($e->getWrappedExceptions())[0] ?? $e;
        }
    }
}
