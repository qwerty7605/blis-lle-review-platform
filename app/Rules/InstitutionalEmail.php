<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passes only when the address's domain is exactly the institution's.
 *
 * The comparison deliberately isolates the domain rather than searching the
 * whole address. A substring test -- str_contains($email, 'lnu.edu.ph') --
 * would accept someone@lnu.edu.ph.example.com, and anyone can register that
 * domain and hand out mailboxes on it. Splitting at the last "@" is the part
 * that makes this check mean anything.
 *
 * Note this proves affiliation with the institution, not enrolment in any
 * particular programme: every LNU mailbox holder passes, BLIS or otherwise.
 */
class InstitutionalEmail implements ValidationRule
{
    public function __construct(private readonly string $domain)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail('The :attribute must be a valid email address.');

            return;
        }

        $separator = strrpos($value, '@');

        if ($separator === false) {
            $fail('The :attribute must be a valid email address.');

            return;
        }

        if (strtolower(substr($value, $separator + 1)) !== strtolower($this->domain)) {
            $fail("Registration is limited to {$this->domain} email addresses.");
        }
    }
}
