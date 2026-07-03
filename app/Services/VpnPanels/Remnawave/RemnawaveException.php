<?php

namespace App\Services\VpnPanels\Remnawave;

use RuntimeException;

/**
 * Thrown for any Remnawave API failure. Messages are user-safe Persian strings
 * and never contain the JWT token, headers, or raw credentials.
 */
class RemnawaveException extends RuntimeException {}
