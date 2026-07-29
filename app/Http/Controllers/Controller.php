<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Gives every controller `$this->authorize(...)`, so ownership checks go
     * through the policies in app/Policies instead of being re-written inline.
     */
    use AuthorizesRequests;
}
