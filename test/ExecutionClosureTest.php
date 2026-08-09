<?php

/*
 * This file is part of Psy Shell.
 *
 * (c) 2012-2026 Justin Hileman
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Psy\Test;

use Psy\Configuration;
use Psy\Exception\BreakException;
use Psy\ExecutionLoop\AbstractListener;
use Psy\Shell;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ExecutionClosureTest extends TestCase
{
    private ?\Closure $errorHandler = null;

    protected function tearDown(): void
    {
        if ($this->errorHandler !== null) {
            $currentHandler = \set_error_handler(static function () {
                return true;
            });
            \restore_error_handler();

            if ($currentHandler !== $this->errorHandler) {
                // Drop the extra handler frame left by a failing loadIncludes() call.
                \restore_error_handler();
            }

            \restore_error_handler();
            $this->errorHandler = null;
        }

        if (\function_exists('readline_clear_history')) {
            \readline_clear_history();
        }

        parent::tearDown();
    }

    public function testAfterLoopRunsAfterSuccessfulExecutionIsSettled()
    {
        [$shell, $listener] = $this->getShell();

        $this->assertSame(42, $shell->execute('echo "hello"; $answer = 42; $answer', true));
        $this->assertSame(1, $listener->afterLoopCalls);
        $this->assertSame(42, $listener->scopeVariables['answer']);
        $this->assertSame('hello', $listener->scopeVariables['__out']);
        $this->assertSame([false, false], $listener->runActiveStates);
    }

    public function testAfterLoopRunsAfterFailedExecutionIsCleanedUp()
    {
        [$shell, $listener] = $this->getShell();
        $outputBufferLevel = \ob_get_level();

        try {
            $shell->execute('throw new \RuntimeException("failed")', true);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('failed', $e->getMessage());
        }

        $this->assertSame(1, $listener->afterLoopCalls);
        $this->assertSame($outputBufferLevel, $listener->outputBufferLevel);
        $this->assertSame([false, false], $listener->runActiveStates);
    }

    public function testFullRunOwnsRunStateForItsCompleteLifecycle()
    {
        [$shell, $listener] = $this->getShell([
            'interactiveMode' => Configuration::INTERACTIVE_MODE_DISABLED,
        ]);
        $shell->addInput('21 * 2', true);

        $this->assertSame(0, $shell->doRun(new ArrayInput([]), new BufferedOutput()));
        $this->assertSame([true, true, true, true], $listener->runActiveStates);
        $this->assertFalse($shell->isRunActive());
    }

    public function testFullRunSettlesNestedExecutionsOnce()
    {
        [$shell, $listener] = $this->getShell([
            'interactiveMode' => Configuration::INTERACTIVE_MODE_DISABLED,
        ]);
        $shell->addInput('timeit -n3 1 + 1', true);
        $shell->addInput('exit', true);

        $this->assertSame(0, $shell->doRun(new ArrayInput([]), new BufferedOutput()));
        $this->assertSame(3, $listener->onExecuteCalls);
        $this->assertSame(1, $listener->afterLoopCalls);
    }

    public function testFullRunReleasesRunStateAfterFailure()
    {
        [$shell, $listener] = $this->getShell([
            'interactiveMode' => Configuration::INTERACTIVE_MODE_DISABLED,
        ]);
        $listener->failBeforeRun = true;

        try {
            $shell->doRun(new ArrayInput([]), new BufferedOutput());
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('failed', $e->getMessage());
        }

        $this->assertSame([true], $listener->runActiveStates);
        $this->assertFalse($shell->isRunActive());
    }

    public function testInteractiveRunSettlesListenersAfterThrowUp()
    {
        [$shell, $listener] = $this->getShell([
            'interactiveMode' => Configuration::INTERACTIVE_MODE_FORCED,
        ]);
        $shell->addInput('throw-up new \RuntimeException("failed")', true);

        try {
            $shell->doRun(new ArrayInput([]), new BufferedOutput());
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('failed', $e->getMessage());
        }

        $this->assertSame(1, $listener->afterLoopCalls);
        $this->assertSame(1, $listener->afterRunCalls);
        $this->assertSame([1], $listener->exitCodes);
    }

    public function testInteractiveRunRendersListenerFailuresBeforeTeardown()
    {
        [$shell, $listener] = $this->getShell([
            'interactiveMode' => Configuration::INTERACTIVE_MODE_FORCED,
        ]);
        $listener->failBeforeLoop = true;
        $output = new BufferedOutput();

        $this->assertSame(1, $shell->doRun(new ArrayInput([]), $output));
        $this->assertSame(1, \substr_count($output->fetch(), 'failed before loop'));
        $this->assertSame(0, $listener->afterLoopCalls);
        $this->assertSame(1, $listener->afterRunCalls);
        $this->assertSame([1], $listener->exitCodes);
    }

    public function testNonInteractiveRunSettlesListenersAfterIncludeFailure()
    {
        [$shell, $listener] = $this->getShell([
            'interactiveMode' => Configuration::INTERACTIVE_MODE_DISABLED,
        ]);
        $shell->failIncludes = true;
        $output = new BufferedOutput();
        $this->errorHandler = static function () {
            return true;
        };
        \set_error_handler($this->errorHandler);

        $this->assertSame(1, $shell->doRun(new ArrayInput([]), $output));
        $this->assertSame(1, \substr_count($output->fetch(), 'failed include'));
        $this->assertSame(0, $listener->afterLoopCalls);
        $this->assertSame(1, $listener->afterRunCalls);
        $this->assertSame([1], $listener->exitCodes);

        $shell->writeException(new BreakException('finished'));
        $this->assertStringContainsString('finished', $output->fetch());
    }

    public function testNonInteractiveRunClearsStateWhenListenerTeardownFails()
    {
        [$shell, $listener] = $this->getShell([
            'interactiveMode' => Configuration::INTERACTIVE_MODE_DISABLED,
        ]);
        $listener->failAfterRun = true;
        $shell->addInput('21 * 2', true);
        $output = new BufferedOutput();

        try {
            $shell->doRun(new ArrayInput([]), $output);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('failed after run', $e->getMessage());
        }

        $output->fetch();
        $shell->writeException(new BreakException('finished'));
        $this->assertStringContainsString('finished', $output->fetch());
    }

    /**
     * @return array{ExecutionClosureTestShell, ExecutionClosureListener}
     */
    private function getShell(array $options = []): array
    {
        $dir = TempPaths::reserve('psysh-test-execution-closure-');
        $config = new Configuration(\array_merge([
            'configDir'    => $dir,
            'dataDir'      => $dir,
            'runtimeDir'   => $dir,
            'trustProject' => false,
        ], $options));
        $listener = new ExecutionClosureListener();
        $shell = new ExecutionClosureTestShell($config, $listener);
        $shell->setOutput(new BufferedOutput());

        return [$shell, $listener];
    }
}

class ExecutionClosureListener extends AbstractListener
{
    public int $afterLoopCalls = 0;
    public int $afterRunCalls = 0;
    public array $exitCodes = [];
    public bool $failAfterRun = false;
    public bool $failBeforeLoop = false;
    public bool $failBeforeRun = false;
    public int $onExecuteCalls = 0;
    public ?int $outputBufferLevel = null;
    public array $scopeVariables = [];
    public array $runActiveStates = [];

    public static function isSupported(): bool
    {
        return true;
    }

    public function beforeRun(Shell $shell)
    {
        $this->runActiveStates[] = $shell->isRunActive();

        if ($this->failBeforeRun) {
            throw new \RuntimeException('failed');
        }
    }

    public function onExecute(Shell $shell, string $code)
    {
        $this->onExecuteCalls++;
        $this->runActiveStates[] = $shell->isRunActive();

        return null;
    }

    public function beforeLoop(Shell $shell)
    {
        if ($this->failBeforeLoop) {
            throw new \RuntimeException('failed before loop');
        }
    }

    public function afterLoop(Shell $shell)
    {
        $this->afterLoopCalls++;
        $this->outputBufferLevel = \ob_get_level();
        $this->scopeVariables = $shell->getScopeVariables();
        $this->runActiveStates[] = $shell->isRunActive();
    }

    public function afterRun(Shell $shell, int $exitCode = 0)
    {
        $this->afterRunCalls++;
        $this->exitCodes[] = $exitCode;
        $this->runActiveStates[] = $shell->isRunActive();

        if ($this->failAfterRun) {
            throw new \RuntimeException('failed after run');
        }
    }
}

class ExecutionClosureTestShell extends Shell
{
    public bool $failIncludes = false;
    private ExecutionClosureListener $listener;

    public function __construct(Configuration $config, ExecutionClosureListener $listener)
    {
        $this->listener = $listener;

        parent::__construct($config);
    }

    protected function getDefaultLoopListeners(): array
    {
        return [$this->listener];
    }

    public function getIncludes(): array
    {
        if ($this->failIncludes) {
            throw new \RuntimeException('failed include');
        }

        return parent::getIncludes();
    }
}
