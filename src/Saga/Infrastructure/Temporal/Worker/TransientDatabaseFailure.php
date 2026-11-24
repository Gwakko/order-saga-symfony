<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Temporal\Worker;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\RetryableException;

/**
 * Tells a database failure that goes away by itself (the connection dropped, Postgres restarts, a deadlock) from a
 * business or programming error. The first kind is retried by Temporal like any other activity failure, after the
 * connection is dropped so the retry reconnects (EC-85); the second kind keeps its own handling.
 */
final readonly class TransientDatabaseFailure
{
    /** SQLSTATE classes: 08 connection exception, 53 insufficient resources (too many connections), 57P admin shutdown / crash recovery. */
    private const array TRANSIENT_STATE_PREFIXES = ['08', '53', '57P'];

    /** libpq / PDO messages that carry no SQLSTATE when the socket is already gone. */
    private const array TRANSIENT_MESSAGE_PARTS = [
        'server closed the connection unexpectedly',
        'terminating connection',
        'no connection to the server',
        'connection refused',
        'could not translate host name',
        'the database system is starting up',
        'the database system is shutting down',
        'ssl syscall error',
        'could not receive data from server',
        'could not send data to server',
    ];

    public static function matches(\Throwable $failure): bool
    {
        for ($current = $failure; $current !== null; $current = $current->getPrevious()) {
            if (self::isTransient($current)) {
                return true;
            }
        }

        return false;
    }

    private static function isTransient(\Throwable $failure): bool
    {
        if ($failure instanceof ConnectionLost || $failure instanceof ConnectionException || $failure instanceof RetryableException) {
            return true;
        }

        $state = match (true) {
            $failure instanceof DriverException => $failure->getSQLState(),
            $failure instanceof \PDOException => is_string($failure->errorInfo[0] ?? null) ? $failure->errorInfo[0] : null,
            default => null,
        };

        if ($state !== null && array_any(self::TRANSIENT_STATE_PREFIXES, static fn(string $prefix): bool => str_starts_with($state, $prefix))) {
            return true;
        }

        if (!$failure instanceof \PDOException && !$failure instanceof DriverException) {
            return false;
        }

        $message = strtolower($failure->getMessage());

        return array_any(self::TRANSIENT_MESSAGE_PARTS, static fn(string $part): bool => str_contains($message, $part));
    }
}
