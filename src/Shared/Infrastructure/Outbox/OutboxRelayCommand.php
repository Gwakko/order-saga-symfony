<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The relay loop: `bin/console app:outbox:relay`. Run several copies for throughput; rows are locked per batch.
 */
#[AsCommand(name: 'app:outbox:relay', description: 'Publishes unpublished outbox messages to the message transport')]
final class OutboxRelayCommand extends Command implements SignalableCommandInterface
{
    private bool $stop = false;

    public function __construct(private readonly OutboxRelay $relay, private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows per batch', '100')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Milliseconds to wait when there is nothing to publish', '500')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Drain the outbox and exit');
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return [\SIGTERM, \SIGINT];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stop = true;

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batch = max(1, (int) $this->stringOption($input, 'batch'));
        $sleepMs = max(0, (int) $this->stringOption($input, 'sleep'));
        $once = $input->getOption('once') === true;

        while (!$this->stop) {
            try {
                $published = $this->relay->publishBatch($batch);
            } catch (\Throwable $e) {
                $this->logger->error('Outbox relay batch failed, will retry.', ['error' => $e->getMessage()]);
                $published = 0;

                if ($once) {
                    return Command::FAILURE;
                }
            }

            if ($published > 0) {
                $output->writeln(sprintf('published %d', $published), OutputInterface::VERBOSITY_VERBOSE);
            }

            if ($once && $published === 0) {
                break;
            }

            if ($published < $batch && !$once) {
                usleep($sleepMs * 1000);
            }
        }

        return Command::SUCCESS;
    }

    private function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : '0';
    }
}
