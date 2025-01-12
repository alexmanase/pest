<?php

declare(strict_types=1);

namespace Pest;

use Pest\Contracts\Bootstrapper;
use Pest\Exceptions\NoTestsFound;
use Pest\Plugins\Actions\CallsAddsOutput;
use Pest\Plugins\Actions\CallsBoot;
use Pest\Plugins\Actions\CallsShutdown;
use Pest\Support\Container;
use PHPUnit\TextUI\Application;
use PHPUnit\TextUI\Exception;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
final class Kernel
{
    /**
     * The Kernel bootstrappers.
     *
     * @var array<int, class-string>
     */
    private const BOOTSTRAPPERS = [
        Bootstrappers\BootOverrides::class,
        Bootstrappers\BootExceptionHandler::class,
        Bootstrappers\BootSubscribers::class,
        Bootstrappers\BootFiles::class,
        Bootstrappers\BootView::class,
    ];

    /**
     * Creates a new Kernel instance.
     */
    public function __construct(
        private readonly Application $application
    ) {
        register_shutdown_function(function (): void {
            if (error_get_last() !== null) {
                return;
            }

            $this->shutdown();
        });
    }

    /**
     * Boots the Kernel.
     */
    public static function boot(): self
    {
        foreach (self::BOOTSTRAPPERS as $bootstrapper) {
            $bootstrapper = Container::getInstance()->get($bootstrapper);
            assert($bootstrapper instanceof Bootstrapper);

            $bootstrapper->boot();
        }

        (new CallsBoot())->__invoke();

        return new self(new Application());
    }

    /**
     * Handles the given argv.
     *
     * @param  array<int, string>  $argv
     *
     * @throws Exception
     */
    public function handle(OutputInterface $output, array $argv): int
    {
        $argv = (new Plugins\Actions\CallsHandleArguments())->__invoke($argv);

        try {
            $this->application->run($argv);
        } catch (NoTestsFound) {
            $output->writeln([
                '',
                '  <fg=white;options=bold;bg=blue> INFO </> No tests found.',
                '',
            ]);
        }

        return (new CallsAddsOutput())->__invoke(
            Result::exitCode(),
        );
    }

    /**
     * Shutdown the Kernel.
     */
    public function shutdown(): void
    {
        (new CallsShutdown())->__invoke();
    }
}
