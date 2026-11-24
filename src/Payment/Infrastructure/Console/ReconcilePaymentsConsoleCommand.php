<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Console;

use App\Payment\Application\Command\ReconcilePayments\ReconcilePaymentsCommand;
use App\Payment\Application\Command\ReconcilePayments\ReconcilePaymentsHandler;
use App\Shared\Application\Exception\InvalidInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `ReconcilePayments` (02_use_cases.md section 3, EC-28): asks the gateway about payments nobody confirmed and refunds
 * late successes of orders that ended without a key. Scheduled every few minutes.
 */
#[AsCommand(name: 'app:payments:reconcile', description: 'Reconciles Unknown/Abandoned/RefundPending payments with the gateway')]
final class ReconcilePaymentsConsoleCommand extends Command
{
    public function __construct(private readonly ReconcilePaymentsHandler $handler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, sprintf('Only payments untouched for this many seconds (>= %d)', ReconcilePaymentsCommand::MIN_AGE_SECONDS), '300')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf('Payments per run (1..%d)', ReconcilePaymentsCommand::MAX_LIMIT), '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = ($this->handler)(new ReconcilePaymentsCommand($this->intOption($input, 'older-than'), $this->intOption($input, 'limit')));
        } catch (InvalidInput $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::INVALID;
        }

        $output->writeln(sprintf('checked %d, succeeded late %d, refunded %d', $result->checked, $result->succeededLate, $result->refunded));

        return Command::SUCCESS;
    }

    private function intOption(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }
}
