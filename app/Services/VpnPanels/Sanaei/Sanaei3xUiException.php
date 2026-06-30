<?php

namespace App\Services\VpnPanels\Sanaei;

use RuntimeException;

/**
 * Raised on 3X-UI API failures. Messages are safe for logging/UI — they never
 * contain tokens, passwords, cookies or session data.
 */
class Sanaei3xUiException extends RuntimeException
{
}
