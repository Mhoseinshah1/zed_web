<?php

namespace App\Policies;

/**
 * Ownership for Notification. Behaviour lives in OwnedModelPolicy so the customer
 * and administrative surfaces cannot drift apart per model.
 */
class NotificationPolicy extends OwnedModelPolicy {}
