<?php

namespace App;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The fallback HttpKernel Logger keeps every record of the process; it is not a
 * kernel.reset service, so a long-running worker must drop them itself.
 */
final class LogReset implements ResetInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function reset(): void
    {
        if ($this->logger instanceof Logger) {
            $this->logger->clear();
        }
    }
}
