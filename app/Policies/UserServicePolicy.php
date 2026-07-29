<?php

namespace App\Policies;

/**
 * Ownership for UserService. Behaviour lives in OwnedModelPolicy so the customer
 * and administrative surfaces cannot drift apart per model.
 */
class UserServicePolicy extends OwnedModelPolicy {}
