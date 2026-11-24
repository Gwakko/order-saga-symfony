<?php

declare(strict_types=1);

namespace App\Tests\Unit\Migration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-ARCH-04: no FOREIGN KEY between tables of different modules (00_overview.md section 5.2). The migrations' SQL is
 * parsed statically; a table missing from the ownership map fails the test, so a new table cannot dodge the check.
 */
#[Group('T-ARCH-04')]
final class MigrationForeignKeyTest extends TestCase
{
    private const array OWNER = [
        'orders' => 'Ordering',
        'order_view' => 'Ordering',
        'products' => 'Inventory',
        'product_keys' => 'Inventory',
        'key_reservations' => 'Inventory',
        'product_availability_view' => 'Inventory',
        'payments' => 'Payment',
        'outbox_messages' => 'Shared',
        'inbox_messages' => 'Shared',
        'idempotency_keys' => 'Shared',
    ];

    public function testMigrationsHaveNoCrossModuleForeignKeys(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/migrations/Version*.php');
        self::assertIsArray($files);
        self::assertNotSame([], $files);

        $created = [];
        $violations = [];

        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);

            foreach (self::createdTables($sql) as $table) {
                $created[] = $table;
            }

            foreach (self::crossModuleReferences($sql) as $violation) {
                $violations[] = basename($file) . ': ' . $violation;
            }
        }

        self::assertSame([], array_values(array_diff($created, array_keys(self::OWNER))), 'every created table must be in the module ownership map');
        self::assertSame([], $violations);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('samples')]
    public function testParserFindsViolations(string $sql, array $expected): void
    {
        self::assertSame($expected, self::crossModuleReferences($sql));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function samples(): iterable
    {
        yield 'inline reference across modules' => [
            'CREATE TABLE payments (id uuid, order_id uuid REFERENCES orders (id))',
            ['payments (Payment) -> orders (Ordering)'],
        ];
        yield 'constraint in create table' => [
            'CREATE TABLE key_reservations (id uuid, order_id uuid, CONSTRAINT fk FOREIGN KEY (order_id) REFERENCES orders (id))',
            ['key_reservations (Inventory) -> orders (Ordering)'],
        ];
        yield 'alter table add constraint' => [
            'ALTER TABLE payments ADD CONSTRAINT fk FOREIGN KEY (order_id) REFERENCES orders (id)',
            ['payments (Payment) -> orders (Ordering)'],
        ];
        yield 'same module is fine' => [
            'ALTER TABLE key_reservations ADD CONSTRAINT fk FOREIGN KEY (key_id) REFERENCES product_keys (id)',
            [],
        ];
        yield 'no foreign key' => ['CREATE TABLE orders (id uuid PRIMARY KEY)', []];
    }

    /**
     * @return list<string>
     */
    private static function createdTables(string $sql): array
    {
        preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?"?(\w+)"?/i', $sql, $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private static function crossModuleReferences(string $sql): array
    {
        $violations = [];
        $statements = preg_split('/(?=CREATE\s+TABLE|ALTER\s+TABLE)/i', $sql);
        self::assertIsArray($statements);

        foreach ($statements as $statement) {
            if (preg_match('/^(?:CREATE|ALTER)\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?"?(\w+)"?/i', $statement, $owner) !== 1) {
                continue;
            }

            preg_match_all('/REFERENCES\s+"?(\w+)"?/i', $statement, $targets);

            foreach ($targets[1] as $target) {
                $from = self::OWNER[$owner[1]] ?? 'unknown';
                $to = self::OWNER[$target] ?? 'unknown';

                if ($from !== $to) {
                    $violations[] = sprintf('%s (%s) -> %s (%s)', $owner[1], $from, $target, $to);
                }
            }
        }

        return $violations;
    }
}
