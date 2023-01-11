<?php

declare(strict_types=1);

namespace Pest\Logging\TeamCity;

use NunoMaduro\Collision\Adapters\Phpunit\State;
use NunoMaduro\Collision\Adapters\Phpunit\TestResult;
use Pest\Exceptions\ShouldNotHappen;
use Pest\Support\Str;
use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestDox;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Code\Throwable;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\TestData\TestDataCollection;
use PHPUnit\Event\TestSuite\TestSuite;
use PHPUnit\Framework\Exception as FrameworkException;
use PHPUnit\Framework\IncompleteTestError;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Metadata\MetadataCollection;
use PHPUnit\TestRunner\TestResult\TestResult as PhpUnitTestResult;

/**
 * @internal
 */
final class Converter
{
    /**
     * @var string
     */
    private const PREFIX = 'P\\';

    public function __construct(
        private readonly string $rootPath,
    ) {
    }

    public function getTestCaseMethodName(Test $test): string
    {
        if (! $test instanceof TestMethod) {
            throw ShouldNotHappen::fromMessage('Not an instance of TestMethod');
        }

        return $test->testDox()->prettifiedMethodName();
    }

    public function getTestCaseLocation(Test $test): string
    {
        if (! $test instanceof TestMethod) {
            throw ShouldNotHappen::fromMessage('Not an instance of TestMethod');
        }

        $fileName = $test->testDox()->prettifiedClassName();
        $fileName = $this->cleanPath($fileName);

        // TODO: Get the description without the dataset.
        $description = $test->testDox()->prettifiedMethodName();

        return "$fileName::$description";
    }

    public function getExceptionMessage(Throwable $throwable): string
    {
        if (is_a($throwable->className(), FrameworkException::class, true)) {
            return $throwable->message();
        }

        $buffer = $throwable->className();
        $throwableMessage = $throwable->message();

        if ($throwableMessage !== '') {
            $buffer .= ": $throwableMessage";
        }

        return $buffer;
    }

    public function getExceptionDetails(Throwable $throwable): string
    {
        $buffer = $this->getStackTrace($throwable);

        while ($throwable->hasPrevious()) {
            $throwable = $throwable->previous();

            $buffer .= sprintf(
                "\nCaused by\n%s\n%s",
                $throwable->description(),
                $this->getStackTrace($throwable)
            );
        }

        return $buffer;
    }

    public function getStackTrace(Throwable $throwable): string
    {
        $stackTrace = $throwable->stackTrace();

        // Split stacktrace per frame.
        $frames = explode("\n", $stackTrace);

        // Remove empty lines
        $frames = array_filter($frames);

        // clean the paths of each frame.
        $frames = array_map(
            fn (string $frame): string => $this->cleanPath($frame),
            $frames
        );

        // Format stacktrace as `at <path>`
        $frames = array_map(
            fn (string $frame) => "at $frame",
            $frames
        );

        return implode("\n", $frames);
    }

    public function getTestSuiteName(TestSuite $testSuite): string
    {
        $name = $testSuite->name();

        if (! str_starts_with($name, self::PREFIX)) {
            return $name;
        }

        return Str::after($name, self::PREFIX);
    }

    public function getTestSuiteLocation(TestSuite $testSuite): string|null
    {
        $tests = $testSuite->tests()->asArray();

        // TODO: figure out how to get the file path without a test being there.
        if ($tests === []) {
            return null;
        }

        $firstTest = $tests[0];
        if (! $firstTest instanceof TestMethod) {
            throw ShouldNotHappen::fromMessage('Not an instance of TestMethod');
        }

        $path = $firstTest->testDox()->prettifiedClassName();

        return $this->cleanPath($path);
    }

    private function cleanPath(string $path): string
    {
        // Remove cwd from the path.
        return str_replace("$this->rootPath/", '', $path);
    }

    public function getStateFromResult(PhpUnitTestResult $result): State
    {
        $state = new State();

        foreach ($result->testErroredEvents() as $resultEvent) {
            assert($resultEvent instanceof Errored);
            $state->add(TestResult::fromTestCase(
                $resultEvent->test(),
                TestResult::FAIL,
                $resultEvent->throwable()
            ));
        }

        foreach ($result->testFailedEvents() as $resultEvent) {
            $state->add(TestResult::fromTestCase(
                $resultEvent->test(),
                TestResult::FAIL,
                $resultEvent->throwable()
            ));
        }

        foreach ($result->testMarkedIncompleteEvents() as $resultEvent) {
            $state->add(TestResult::fromTestCase(
                $resultEvent->test(),
                TestResult::INCOMPLETE,
                $resultEvent->throwable()
            ));
        }

        foreach ($result->testConsideredRiskyEvents() as $riskyEvents) {
            foreach ($riskyEvents as $riskyEvent) {
                $state->add(TestResult::fromTestCase(
                    $riskyEvent->test(),
                    TestResult::RISKY,
                    Throwable::from(new IncompleteTestError($riskyEvent->message()))
                ));
            }
        }

        foreach ($result->testSkippedEvents() as $resultEvent) {
            if ($resultEvent->message() === '__TODO__') {
                $state->add(TestResult::fromTestCase($resultEvent->test(), TestResult::TODO));

                continue;
            }

            $state->add(TestResult::fromTestCase(
                $resultEvent->test(),
                TestResult::SKIPPED,
                Throwable::from(new SkippedWithMessageException($resultEvent->message()))
            ));
        }

        $numberOfPassedTests = $result->numberOfTests()
            - $result->numberOfTestErroredEvents()
            - $result->numberOfTestFailedEvents()
            - $result->numberOfTestSkippedEvents()
            - $result->numberOfTestsWithTestConsideredRiskyEvents()
            - $result->numberOfTestMarkedIncompleteEvents();

        for ($i = 0; $i < $numberOfPassedTests; $i++) {
            $state->add(TestResult::fromTestCase(

                new TestMethod(
                    /** @phpstan-ignore-next-line */
                    "$i",
                    /** @phpstan-ignore-next-line */
                    '',
                    '',
                    1,
                    /** @phpstan-ignore-next-line */
                    TestDox::fromClassNameAndMethodName('', ''),
                    MetadataCollection::fromArray([]),
                    TestDataCollection::fromArray([])
                ),
                TestResult::PASS
            ));
        }

        return $state;
    }
}