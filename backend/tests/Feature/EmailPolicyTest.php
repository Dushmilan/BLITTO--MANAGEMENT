<?php

use App\Domain\Authorization\EmailPolicy;

dataset('institution emails', [
    ['a@pdn.ac.lk', true],
    ['  a@pdn.ac.lk  ', true],
    ['A@PDN.AC.LK', true],
    ['u@sci.pdn.ac.lk', true],
    ['u@deep.sci.pdn.ac.lk', true],
    ['u@pdn.ac.lk.evil.com', false],
    ['u@fakepdn.ac.lk', false],
    ['u@notpdn.ac.lk', false],
    ['u@gmail.com', false],
    ['not-an-email', false],
    ['', false],
    ['@pdn.ac.lk', false],
    ['a@@pdn.ac.lk', false],
    ['a @pdn.ac.lk', false],
    ['a@ pdn.ac.lk', false],
    ['a+b@pdn.ac.lk', true],
]);

it('accepts only institution email addresses', function (string $email, bool $expected) {
    expect(EmailPolicy::isInstitution($email))->toBe($expected);
})->with('institution emails');

it('normalizes and extracts the domain', function () {
    expect(EmailPolicy::normalize('  A@PDN.AC.LK '))->toBe('a@pdn.ac.lk')
        ->and(EmailPolicy::domain('A@Sci.Pdn.Ac.Lk'))->toBe('sci.pdn.ac.lk')
        ->and(EmailPolicy::domain('no-at-sign'))->toBe('');
});

it('honours an explicit domain list', function () {
    expect(EmailPolicy::isInstitution('u@eng.pdn.ac.lk', ['pdn.ac.lk']))->toBeTrue()
        ->and(EmailPolicy::isInstitution('u@eng.pdn.ac.lk', ['sci.pdn.ac.lk']))->toBeFalse();
});
