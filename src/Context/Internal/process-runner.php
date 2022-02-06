<?php

namespace Amp\Parallel\Context\Internal;

use Amp\ByteStream;
use Amp\ByteStream\StreamChannel;
use Amp\Future;
use Amp\Parallel\Context\ProcessContext;
use Amp\Parallel\Ipc;
use Amp\Serialization\SerializationException;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;

\define("AMP_CONTEXT", "process");
\define("AMP_CONTEXT_ID", \getmypid());

// Doesn't exist in phpdbg...
if (\function_exists("cli_set_process_title")) {
    @\cli_set_process_title("amp-process");
}

(function (): void {
    $paths = [
        \dirname(__DIR__, 5) . "/autoload.php",
        \dirname(__DIR__, 3) . "/vendor/autoload.php",
    ];

    foreach ($paths as $path) {
        if (\file_exists($path)) {
            $autoloadPath = $path;
            break;
        }
    }

    if (!isset($autoloadPath)) {
        \trigger_error("Could not locate autoload.php in any of the following files: " . \implode(", ", $paths), E_USER_ERROR);
    }

    /** @psalm-suppress UnresolvableInclude */
    require $autoloadPath;
})();

EventLoop::queue(function () use ($argc, $argv): void {
    if (!isset($argv[1])) {
        \trigger_error("No socket path provided", E_USER_ERROR);
    }

    if (!isset($argv[2]) || !is_numeric($argv[2])) {
        \trigger_error("No key length provided", E_USER_ERROR);
    }

    [, $uri, $length] = $argv;

    // Remove script path, socket path, and key length from process arguments.
    $argc -= 3;
    $argv = \array_slice($argv, 3);

    $cancellation = new TimeoutCancellation(ProcessContext::DEFAULT_START_TIMEOUT);

    try {
        $key = Ipc\readKey(ByteStream\getStdin(), $cancellation, (int) $length);
        $socket = Ipc\connect($uri, $key, $cancellation);
    } catch (\Throwable $exception) {
        \trigger_error($exception->getMessage(), E_USER_ERROR);
    }

    $channel = new StreamChannel($socket, $socket);

    try {
        if (!isset($argv[0])) {
            throw new \Error("No script path given");
        }

        if (!\is_file($argv[0])) {
            throw new \Error(\sprintf("No script found at '%s' (be sure to provide the full path to the script)", $argv[0]));
        }

        try {
            // Protect current scope by requiring script within another function.
            $callable = (function () use ($argc, $argv): callable { // Using $argc so it is available to the required script.
                /** @psalm-suppress UnresolvableInclude */
                return require $argv[0];
            })();
        } catch (\TypeError $exception) {
            throw new \Error(\sprintf("Script '%s' did not return a callable function", $argv[0]), 0, $exception);
        } catch (\ParseError $exception) {
            throw new \Error(\sprintf("Script '%s' contains a parse error: " . $exception->getMessage(), $argv[0]), 0, $exception);
        }

        $returnValue = $callable(new ContextChannel($channel));
        $result = new ExitSuccess($returnValue instanceof Future ? $returnValue->await() : $returnValue);
    } catch (\Throwable $exception) {
        $result = new ExitFailure($exception);
    }

    try {
        try {
            $channel->send($result);
        } catch (SerializationException $exception) {
            // Serializing the result failed. Send the reason why.
            $channel->send(new ExitFailure($exception));
        }
    } catch (\Throwable $exception) {
        \trigger_error(sprintf(
            "Could not send result to parent: '%s'; be sure to shutdown the child before ending the parent",
            $exception->getMessage(),
        ), E_USER_ERROR);
    }
});

EventLoop::run();
