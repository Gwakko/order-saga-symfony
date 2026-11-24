<?php

declare(strict_types=1);

namespace GatewayEmulator;

use PDO;

/** Хранилище в SQLite: текущий режим, списания, возвраты. Деньги только int (минимальные единицы). */
final class Store
{
    private readonly PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA busy_timeout = 30000');
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS state (id INTEGER PRIMARY KEY CHECK (id = 1), mode TEXT NOT NULL, remaining INTEGER NOT NULL);
             INSERT OR IGNORE INTO state (id, mode, remaining) VALUES (1, \'ok\', 0);
             CREATE TABLE IF NOT EXISTS charges (
                 idempotency_key TEXT PRIMARY KEY, charge_id TEXT NOT NULL UNIQUE,
                 request_amount INTEGER NOT NULL, request_currency TEXT NOT NULL,
                 amount INTEGER NOT NULL, currency TEXT NOT NULL, status TEXT NOT NULL,
                 complete_at_ms INTEGER NOT NULL DEFAULT 0, executed INTEGER NOT NULL, requests INTEGER NOT NULL
             );
             CREATE TABLE IF NOT EXISTS refunds (
                 idempotency_key TEXT PRIMARY KEY, refund_id TEXT NOT NULL UNIQUE, charge_id TEXT NOT NULL,
                 amount INTEGER NOT NULL, currency TEXT NOT NULL, status TEXT NOT NULL,
                 executed INTEGER NOT NULL, requests INTEGER NOT NULL
             );',
        );
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function transaction(\Closure $work): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $result = $work();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');

            throw $e;
        }
    }

    public function mode(): Mode
    {
        $row = $this->one('SELECT mode, remaining FROM state WHERE id = 1', []);
        $mode = Mode::parse((string) $row['mode']) ?? Mode::ok();

        return $mode->kind === ModeKind::Http503 ? new Mode(ModeKind::Http503, (int) $row['remaining']) : $mode;
    }

    public function setMode(Mode $mode): void
    {
        $this->run('UPDATE state SET mode = ?, remaining = ? WHERE id = 1', [$mode->toString(), $mode->parameter]);
    }

    public function reset(): void
    {
        $this->pdo->exec('DELETE FROM charges; DELETE FROM refunds; UPDATE state SET mode = \'ok\', remaining = 0 WHERE id = 1');
    }

    /** @return ?array<string, mixed> */
    public function charge(string $key): ?array
    {
        return $this->one('SELECT * FROM charges WHERE idempotency_key = ?', [$key]);
    }

    /** @return ?array<string, mixed> */
    public function chargeById(string $chargeId): ?array
    {
        return $this->one('SELECT * FROM charges WHERE charge_id = ?', [$chargeId]);
    }

    public function touchCharge(string $key): void
    {
        $this->run('UPDATE charges SET requests = requests + 1 WHERE idempotency_key = ?', [$key]);
    }

    public function insertCharge(
        string $key,
        string $chargeId,
        int $requestAmount,
        string $requestCurrency,
        int $amount,
        string $currency,
        string $status,
        int $completeAtMs,
        bool $executed,
    ): void {
        $this->run(
            'INSERT INTO charges VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [$key, $chargeId, $requestAmount, $requestCurrency, $amount, $currency, $status, $completeAtMs, (int) $executed],
        );
    }

    /** @return ?array<string, mixed> */
    public function refund(string $key): ?array
    {
        return $this->one('SELECT * FROM refunds WHERE idempotency_key = ?', [$key]);
    }

    public function touchRefund(string $key): void
    {
        $this->run('UPDATE refunds SET requests = requests + 1 WHERE idempotency_key = ?', [$key]);
    }

    public function insertRefund(string $key, string $refundId, string $chargeId, int $amount, string $currency, string $status): void
    {
        $this->run('INSERT INTO refunds VALUES (?, ?, ?, ?, ?, ?, 1, 1)', [$key, $refundId, $chargeId, $amount, $currency, $status]);
    }

    /** @return list<array<string, mixed>> */
    public function all(string $table): array
    {
        $sql = match ($table) {
            'charges' => 'SELECT * FROM charges ORDER BY rowid',
            'refunds' => 'SELECT * FROM refunds ORDER BY rowid',
        };

        return $this->pdo->query($sql)->fetchAll();
    }

    /**
     * @param list<int|string> $params
     * @return ?array<string, mixed>
     */
    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param list<int|string> $params */
    private function run(string $sql, array $params): void
    {
        $this->pdo->prepare($sql)->execute($params);
    }
}
