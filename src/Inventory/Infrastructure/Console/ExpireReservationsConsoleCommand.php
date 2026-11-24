<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Console;

use App\Inventory\Application\Command\ExpireReservations\ExpireReservationsCommand;
use App\Inventory\Application\Command\ExpireReservations\ExpireReservationsHandler;
use App\Shared\Application\Exception\InvalidInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `ExpireReservations` (02_use_cases.md section 3): returns keys of lapsed reservations to stock. Scheduled every minute.
 */
#[AsCommand(name: 'app:reservations:expire', description: 'Expires active reservations past their deadline and frees their keys')]
final class ExpireReservationsConsoleCommand extends Command
{
    public function __construct(private readonly ExpireReservationsHandler $handler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf('Reservations per run (1..%d)', ExpireReservationsCommand::MAX_LIMIT), '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $input->getOption('limit');

        try {
            $result = ($this->handler)(new ExpireReservationsCommand(is_string($limit) && ctype_digit($limit) ? (int) $limit : 0));
        } catch (InvalidInput $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::INVALID;
        }

        $output->writeln(sprintf('expired %d', $result->expiredCount));

        return Command::SUCCESS;
    }
}
