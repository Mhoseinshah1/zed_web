<?php

namespace App\Services\Email;

final readonly class RegistrationEmailPolicy
{
    public function __construct(
        public bool $enabled,
        public bool $required,
    ) {}

    public function shouldRequestOtp(bool $phoneRequired): bool
    {
        return $this->required || ($this->enabled && ! $phoneRequired);
    }
}
