<?php

namespace App\Policies;

/**
 * Ownership for PaymentTransaction. Behaviour lives in OwnedModelPolicy so the customer
 * and administrative surfaces cannot drift apart per model.
 */
class PaymentTransactionPolicy extends OwnedModelPolicy {}
