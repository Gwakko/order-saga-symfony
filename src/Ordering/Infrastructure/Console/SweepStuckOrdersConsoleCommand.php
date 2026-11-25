<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Console;

use App\Ordering\Application\Command\SweepStuckOrders\SweepStuckOrdersCommand;
use App\Ordering\Application\Command\SweepStuckOrders\SweepStuckOrdersHandler;
use App\Shared\Application\Exception\InvalidInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Parks orders whose saga is gone (M8a, EC-90): intermediate status, untouched for a while, no running workflow.
 */
#[AsCommand(name: 'app:orders:sweep-stuck', description: 'Moves orders without a live saga workflow to manual review')]
final class SweepStuckOrdersConsoleCommand extends Command
{
    public function __construct(
        private readonly SweepStuckOrdersHandler $handler,
        #[Autowire('%app.stuck_orders.older_than_seconds%')]
        private readonly int $defaultOlderThanSeconds,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, sprintf('Only orders untouched for this many seconds (>= %d)', SweepStuckOrdersCommand::MIN_AGE_SECONDS), (string) $this->defaultOlderThanSeconds)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf('Orders per run (1..%d)', SweepStuckOrdersCommand::MAX_LIMIT), '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = ($this->handler)(new SweepStuckOrdersCommand($this->intOption($input, 'older-than'), $this->intOption($input, 'limit')));
        } catch (InvalidInput $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::INVALID;
        }

        $output->writeln(sprintf('checked %d, moved to manual review %d, still running %d, skipped %d', $result->checked, $result->interrupted, $result->stillRunning, $result->skipped));

        return Command::SUCCESS;
    }

    private function intOption(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }
}
