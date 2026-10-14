<?php

namespace Tests\Unit\Rules;

use App\Rules\InstitutionalEmail;
use PHPUnit\Framework\TestCase;

/**
 * Registration accepts only addresses on the school's own domain, compared
 * exactly and without regard to letter case.
 */
class InstitutionalEmailTest extends TestCase
{
    private function failures(mixed $value): array
    {
        $messages = [];
        (new InstitutionalEmail('lnu.edu.ph'))->validate('email', $value, function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        return $messages;
    }

    public function test_an_address_on_the_school_domain_passes_in_any_case(): void
    {
        $this->assertSame([], $this->failures('juan@lnu.edu.ph'));
        $this->assertSame([], $this->failures('Juan@LNU.EDU.PH'));
    }

    public function test_another_domain_or_a_look_alike_is_refused(): void
    {
        $this->assertSame(['Registration is limited to lnu.edu.ph email addresses.'], $this->failures('juan@gmail.com'));
        $this->assertSame(['Registration is limited to lnu.edu.ph email addresses.'], $this->failures('juan@lnu.edu.ph.example.com'));
    }

    public function test_something_that_is_not_an_address_is_refused(): void
    {
        $this->assertSame(['The :attribute must be a valid email address.'], $this->failures(['not', 'a', 'string']));
        $this->assertSame(['The :attribute must be a valid email address.'], $this->failures('no-at-sign'));
    }
}
