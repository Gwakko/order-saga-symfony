<?php

declare(strict_types=1);

namespace App\Tests\Workflow\Support;

use React\Promise\PromiseInterface;
use Spiral\Goridge\RPC\RPC;
use Spiral\RoadRunner\Environment;
use Spiral\RoadRunner\KeyValue\Factory;
use Spiral\RoadRunner\KeyValue\StorageInterface;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\Worker\ActivityInvocationCache\ActivityInvocationCacheInterface;
use Temporal\Worker\ActivityInvocationCache\RoadRunnerActivityInvocationCache;
use Temporal\Worker\InvocationFailure;
use Temporal\Worker\InvocationResult;
use Temporal\Worker\Transport\Command\ServerRequestInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * The stock activity mock answers every call of an activity the same way (one failure or one completion) and its queue
 * holds completions only. The inspection branch calls `ChargePayment` twice (the exhausted charge, then the recovery) and
 * the first call must FAIL while the second SUCCEEDS, so this cache adds `expectSequence()`: one answer per call, failures
 * and completions mixed. Everything else is delegated to the stock RoadRunner cache, which uses the same `kv` store.
 */
final class SequencedActivityInvocationCache implements ActivityInvocationCacheInterface
{
    private const string STORE = 'test';

    public function __construct(
        private readonly RoadRunnerActivityInvocationCache $inner,
        private readonly StorageInterface $storage,
        private readonly DataConverterInterface $converter,
    ) {}

    public static function create(?DataConverterInterface $converter = null): self
    {
        $converter ??= DataConverter::createDefault();
        $address = Environment::fromGlobals()->getRPCAddress();
        $storage = (new Factory(RPC::create($address !== '' ? $address : 'tcp://127.0.0.1:6001')))->select(self::STORE);

        return new self(RoadRunnerActivityInvocationCache::create($converter), $storage, $converter);
    }

    /**
     * @param non-empty-string                     $activityMethodName
     * @param non-empty-list<\Throwable|mixed>     $answers            a Throwable fails the call, anything else completes it
     */
    public function expectSequence(string $activityMethodName, array $answers): void
    {
        $items = [];

        foreach ($answers as $answer) {
            $items[] = $answer instanceof \Throwable
                ? InvocationFailure::fromThrowable($answer, $this->converter)
                : InvocationResult::fromValue($answer, $this->converter);
        }

        $this->storage->set($activityMethodName, new InvocationSequence($items));
    }

    public function clear(): void
    {
        $this->inner->clear();
    }

    public function saveCompletion(string $activityMethodName, mixed $value): void
    {
        $this->inner->saveCompletion($activityMethodName, $value);
    }

    public function saveFailure(string $activityMethodName, \Throwable $error): void
    {
        $this->inner->saveFailure($activityMethodName, $error);
    }

    public function saveConsecutiveCompletions(string $activityMethodName, array $values): void
    {
        $this->inner->saveConsecutiveCompletions($activityMethodName, $values);
    }

    public function saveCompletionWhen(string $activityMethodName, array $args, mixed $value): void
    {
        $this->inner->saveCompletionWhen($activityMethodName, $args, $value);
    }

    public function canHandle(ServerRequestInterface $request): bool
    {
        return $this->inner->canHandle($request);
    }

    /** @return PromiseInterface<mixed> */
    public function execute(ServerRequestInterface $request): PromiseInterface
    {
        $name = $request->getOptions()['name'] ?? '';
        $value = is_string($name) ? $this->storage->get($name) : null;

        if (!$value instanceof InvocationSequence) {
            return $this->inner->execute($request);
        }

        $item = $value->current();
        $value->advance();
        $this->storage->set($name, $value);

        return $item instanceof InvocationFailure
            ? reject($item->toThrowable($this->converter))
            : resolve($item->toEncodedValues($this->converter));
    }
}
