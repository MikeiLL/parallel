<?php declare(strict_types=1);

namespace Amp\Parallel\Worker;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\StatusError;
use Amp\Pipeline\Queue;
use Amp\Sync\ChannelException;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;
use function Amp\async;

/**
 * Default worker implementation executing {@see Task}s.
 *
 * Use {@see runTasks()} to run tasks.
 */
final class DefaultWorker implements Worker
{
    use ForbidCloning;
    use ForbidSerialization;

    private const SHUTDOWN_TIMEOUT = 1;
    private const ERROR_TIMEOUT = 0.25;

    /** @var array<string, DeferredFuture> */
    private array $jobQueue = [];

    /** @var array<string, Queue> */
    private array $queues = [];

    private readonly \Closure $onReceive;

    private ?Future $exitStatus = null;

    /**
     * @param Context<int, Internal\JobPacket, Internal\JobPacket|int> $context A context running an instance of
     * {@see runTasks()}.
     */
    public function __construct(private readonly Context $context)
    {
        $jobQueue = &$this->jobQueue;
        $queues = &$this->queues;
        $this->onReceive = $onReceive = static function (
            ?\Throwable $exception,
            ?Internal\JobPacket $data
        ) use (
            $context,
            &$jobQueue,
            &$queues,
            &$onReceive,
        ): void {
            if (!$data) {
                $exception ??= new WorkerException("Unexpected error in worker");
                foreach ($queues as $queue) {
                    $queue->error($exception);
                }
                foreach ($jobQueue as $deferred) {
                    $deferred->error($exception);
                }
                $context->close();
                return;
            }

            $id = $data->getId();

            try {
                if (!isset($jobQueue[$id], $queues[$id])) {
                    return;
                }

                if ($data instanceof Internal\JobMessage) {
                    $queues[$id]->pushAsync($data->getMessage())->ignore();
                    return;
                }

                if (!$data instanceof Internal\TaskResult) {
                    return;
                }

                $deferred = $jobQueue[$id];
                $queue = $queues[$id];
                unset($jobQueue[$id], $queues[$id]);

                $queue->complete();

                try {
                    $deferred->complete($data->getResult());
                } catch (\Throwable $exception) {
                    $deferred->error($exception);
                }
            } finally {
                if (!empty($jobQueue)) {
                    self::receive($context, $onReceive);
                }
            }
        };
    }

    private static function receive(Context $context, callable $onReceive): void
    {
        EventLoop::queue(static function () use ($context, $onReceive): void {
            try {
                $received = $context->receive();
            } catch (\Throwable $exception) {
                $onReceive($exception, null);
                return;
            }

            $onReceive(null, $received);
        });
    }

    public function isRunning(): bool
    {
        // Report as running unless shutdown or killed.
        return $this->exitStatus === null;
    }

    public function isIdle(): bool
    {
        return empty($this->jobQueue);
    }

    public function submit(Task $task, ?Cancellation $cancellation = null): Execution
    {
        if ($this->exitStatus) {
            throw new StatusError("The worker has been shut down");
        }

        $receive = empty($this->jobQueue);
        $submission = new Internal\TaskSubmission($task);
        $jobId = $submission->getId();
        $this->jobQueue[$jobId] = $deferred = new DeferredFuture;
        $future = $deferred->getFuture();

        try {
            $this->context->send($submission);
        } catch (ChannelException $exception) {
            try {
                $exception = new WorkerException("The worker exited unexpectedly", 0, $exception);
                async($this->context->join(...))
                    ->await(new TimeoutCancellation(self::ERROR_TIMEOUT));
            } catch (CancelledException) {
                $this->kill();
            } catch (\Throwable $exception) {
                $exception = new WorkerException("The worker crashed", 0, $exception);
            }

            $this->exitStatus ??= Future::error($exception);

            unset($this->jobQueue[$jobId]);
            throw $exception;
        } catch (\Throwable $exception) {
            unset($this->jobQueue[$jobId]);
            throw $exception;
        }

        if ($cancellation) {
            $context = $this->context;
            $cancellationId = $cancellation->subscribe(static fn () => async(
                $context->send(...),
                new Internal\JobCancellation($jobId),
            )->ignore());
            $future = $future->finally(static fn () => $cancellation->unsubscribe($cancellationId));
        }

        $this->queues[$jobId] = $queue = new Queue();
        $channel = new Internal\JobChannel($jobId, $this->context, $queue->iterate());

        if ($receive) {
            self::receive($this->context, $this->onReceive);
        }

        /** @psalm-suppress InvalidArgument */
        return new Execution($task, $channel, $future);
    }

    public function shutdown(): void
    {
        if ($this->exitStatus) {
            $this->exitStatus->await();
            return;
        }

        ($this->exitStatus = async(function (): void {
            if ($this->context->isClosed()) {
                throw new WorkerException("The worker had crashed prior to being shutdown");
            }

            // Wait for pending tasks to finish.
            Future\awaitAll(\array_map(static fn (DeferredFuture $deferred) => $deferred->getFuture(), $this->jobQueue));

            $this->context->send(0);

            try {
                async($this->context->join(...))
                    ->await(new TimeoutCancellation(self::SHUTDOWN_TIMEOUT));
            } catch (\Throwable $exception) {
                $this->context->close();
                throw new WorkerException("Failed to gracefully shutdown worker", 0, $exception);
            }
        }))->await();
    }

    public function kill(): void
    {
        if (!$this->context->isClosed()) {
            $this->context->close();
        }

        $this->exitStatus ??= Future::error(new WorkerException("The worker was killed"));
        $this->exitStatus->ignore();
    }
}
