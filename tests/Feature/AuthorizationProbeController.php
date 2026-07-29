<?php

namespace Tests\Feature;

use App\Http\Controllers\Controller;
use App\Models\Order;

/**
 * Test-only controller used to register probe routes that bind an owned model
 * under an unconventional parameter name. It exists so route discovery can be
 * proven to follow the controller SIGNATURE rather than a list of names.
 */
class AuthorizationProbeController extends Controller
{
    /** Correctly guarded — discovery must find it and it must deny a stranger. */
    public function show(Order $invoice): string
    {
        $this->authorize('viewOwned', $invoice);

        return 'ok';
    }

    /** An owned model alongside a scalar — guarded. */
    public function export(Order $invoice, string $format): string
    {
        $this->authorize('viewOwned', $invoice);

        return 'ok:'.$format;
    }

    /** An owned model alongside a scalar — deliberately unguarded. */
    public function unprotectedExport(Order $invoice, string $format): string
    {
        return 'ok:'.$format;
    }

    /** Deliberately unguarded — the coverage test must catch this. */
    public function unprotected(Order $invoice): string
    {
        return 'ok';
    }
}
