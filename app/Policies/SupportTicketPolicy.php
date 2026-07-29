<?php

namespace App\Policies;

/**
 * Ownership for SupportTicket. Behaviour lives in OwnedModelPolicy so the customer
 * and administrative surfaces cannot drift apart per model.
 */
class SupportTicketPolicy extends OwnedModelPolicy {}
