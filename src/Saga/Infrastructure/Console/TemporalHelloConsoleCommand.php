<?php

declare(strict_types=1);

namespace App\Saga\Infrastructure\Console;

use App\Saga\Infrastructure\Temporal\Hello\HelloWorkflow;
use App\Saga\Infrastructure\Temporal\TemporalClientFactory;
use Carbon\CarbonInterval;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Temporal\Client\WorkflowOptions;

/** M6.1 spike: starts `HelloWorkflow` through the gRPC client and prints its result. */
#[AsCommand(name: 'app:temporal:hello', description: 'Runs the hello-world workflow (M6.1 spike)')]
final class TemporalHelloConsoleCommand extends Command
{
    public function __construct(private readonly TemporalClientFactory $clients)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Who to greet', 'world');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $name = is_string($name) ? $name : 'world';

        $workflow = $this->clients->create()->newWorkflowStub(
            HelloWorkflow::class,
            WorkflowOptions::new()->withTaskQueue(HelloWorkflow::TASK_QUEUE)->withWorkflowExecutionTimeout(CarbonInterval::minute()),
        );

        $output->writeln($this->asString($workflow->run($name)));

        return Command::SUCCESS;
    }

    /**
     * The workflow interface declares `\Generator` (the implementation yields), but the client stub returns the final
     * result of the workflow, so the static type lies and the value is checked at runtime.
     */
    private function asString(mixed $result): string
    {
        return is_string($result) ? $result : throw new \UnexpectedValueException('HelloWorkflow returned a non-string result');
    }
}
