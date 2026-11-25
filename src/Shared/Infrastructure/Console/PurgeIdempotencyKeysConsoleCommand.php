<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Application\Command\PurgeIdempotencyKeys\PurgeIdempotencyKeysCommand;
use App\Shared\Application\Command\PurgeIdempotencyKeys\PurgeIdempotencyKeysHandler;
use App\Shared\Application\Exception\InvalidInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:idempotency:purge', description: 'Deletes idempotency keys past their expiry')]
final class PurgeIdempotencyKeysConsoleCommand extends Command
{
    public function __construct(private readonly PurgeIdempotencyKeysHandler $handler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf('Rows per run (1..%d)', PurgeIdempotencyKeysCommand::MAX_LIMIT), '10000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $input->getOption('limit');

        try {
            $result = ($this->handler)(new PurgeIdempotencyKeysCommand(is_string($limit) && ctype_digit($limit) ? (int) $limit : 0));
        } catch (InvalidInput $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::INVALID;
        }

        $output->writeln(sprintf('deleted %d', $result->deleted));

        return Command::SUCCESS;
    }
}
