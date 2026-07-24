<?php

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * Monolog "tap" that installs {@see SensitiveDataProcessor} on a log channel so
 * every record is scrubbed of credentials before it is persisted. Referenced
 * from config/logging.php via the channel's `tap` array.
 */
class SanitizeSensitiveData
{
    public function __invoke(Logger $logger): void
    {
        $processor = new SensitiveDataProcessor;

        foreach ($logger->getHandlers() as $handler) {
            if (method_exists($handler, 'pushProcessor')) {
                $handler->pushProcessor($processor);
            }
        }
    }
}
