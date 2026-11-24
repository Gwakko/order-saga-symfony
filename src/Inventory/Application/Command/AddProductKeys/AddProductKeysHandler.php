<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command\AddProductKeys;

use App\Inventory\Application\Port\KeyInventory;
use App\Inventory\Domain\ProductKey;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Port\IdGenerator;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Identifier\ProductId;
use App\Shared\Domain\Identifier\ProductKeyId;
use App\Shared\Domain\KeyCode;

/**
 * Fills the warehouse. An invalid code rejects the whole batch (nothing is stored); a code that is already present,
 * in the store or earlier in the same batch, counts as a duplicate.
 */
final readonly class AddProductKeysHandler implements CommandHandler
{
    public function __construct(
        private KeyInventory $keys,
        private TransactionManager $transactions,
        private IdGenerator $ids,
    ) {}

    public function __invoke(AddProductKeysCommand $command): AddProductKeysOutput
    {
        $productId = ProductId::fromString($command->productId);
        $codes = array_map(static fn(string $code): KeyCode => new KeyCode($code), $command->codes);

        return $this->transactions->transactional(function () use ($productId, $codes): AddProductKeysOutput {
            $added = 0;
            $duplicates = 0;

            foreach ($codes as $code) {
                $key = ProductKey::add(ProductKeyId::fromString($this->ids->next()), $productId, $code);

                if ($this->keys->addIfAbsent($key)) {
                    ++$added;
                } else {
                    ++$duplicates;
                }
            }

            return new AddProductKeysOutput($added, $duplicates);
        });
    }
}
