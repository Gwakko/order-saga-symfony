<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency;

use App\Shared\Domain\Identifier\CustomerId;
use App\Shared\Infrastructure\Http\CustomerIdHeader;
use App\Shared\Infrastructure\Http\Problem\ProblemDetails;
use App\Shared\Infrastructure\Idempotency\Exception\IdempotencyKeyReused;
use App\Shared\Infrastructure\Idempotency\Exception\IdempotentRequestInProgress;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * HTTP idempotency (02_use_cases.md section 5) as two kernel events around any controller marked `#[Idempotent]`:
 *
 * - `kernel.controller` (before arguments are resolved, so before any payload validation and before the handler):
 *   checks the key, registers the request in the store and either lets it run, replays the stored answer, or refuses.
 * - `kernel.response`: stores the answer of a request this listener started (2xx and 4xx), or forgets the key after
 *   a server error so the client can retry (EC-07). Error responses from the exception listener pass through here too.
 */
final readonly class IdempotencyListener
{
    public const string REPLAY_HEADER = 'Idempotent-Replayed';
    public const string KEY_HEADER = 'Idempotency-Key';

    private const string STARTED_ATTRIBUTE = '_idempotency_started';

    public function __construct(private IdempotencyStore $store, private ClockInterface $clock) {}

    #[AsEventListener(event: KernelEvents::CONTROLLER)]
    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !$request->isMethod(Request::METHOD_POST) || !isset($event->getAttributes()[Idempotent::class])) {
            return;
        }

        $customerId = CustomerIdHeader::fromRequest($request);
        $key = IdempotencyKey::fromHeader($request->headers->get(self::KEY_HEADER));

        $decision = $this->store->begin(
            $customerId,
            $key,
            RequestFingerprint::of($request->getMethod(), $request->getPathInfo(), $request->getContent()),
            $request->getMethod(),
            $request->getPathInfo(),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
        );

        match ($decision->outcome) {
            IdempotencyOutcome::Started => $request->attributes->set(self::STARTED_ATTRIBUTE, [$customerId, $key]),
            IdempotencyOutcome::Replay => $event->setController(static fn(): Response => self::replayed($decision)),
            IdempotencyOutcome::InProgress => throw new IdempotentRequestInProgress(),
            IdempotencyOutcome::KeyReused => throw new IdempotencyKeyReused(),
        };
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $started = $request->attributes->get(self::STARTED_ATTRIBUTE);

        if (!$event->isMainRequest() || !is_array($started)) {
            return;
        }

        $request->attributes->remove(self::STARTED_ATTRIBUTE);
        [$customerId, $key] = $started;

        if (!$customerId instanceof CustomerId || !$key instanceof IdempotencyKey) {
            return;
        }

        $response = $event->getResponse();
        $status = $response->getStatusCode();
        $body = (string) $response->getContent();
        $storable = $status >= Response::HTTP_OK && $status < Response::HTTP_INTERNAL_SERVER_ERROR && $status !== Response::HTTP_NOT_MODIFIED && json_validate($body);

        if ($storable) {
            $this->store->complete($customerId, $key, $status, $body);
        } else {
            $this->store->release($customerId, $key);
        }
    }

    private static function replayed(IdempotencyDecision $decision): Response
    {
        $status = $decision->responseStatus ?? Response::HTTP_OK;
        $response = new JsonResponse($decision->responseBody, $status, [self::REPLAY_HEADER => 'true'], true);

        if ($status >= Response::HTTP_BAD_REQUEST) {
            $response->headers->set('Content-Type', ProblemDetails::MEDIA_TYPE);
        }

        return $response;
    }
}
