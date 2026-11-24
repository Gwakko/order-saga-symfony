<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

/**
 * Marker for command handlers. The Messenger wiring (`_instanceof` in services.yaml) keys on it, so the
 * application layer needs no framework attribute.
 */
interface CommandHandler {}
