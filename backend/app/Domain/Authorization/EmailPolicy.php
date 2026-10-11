<?php

namespace App\Domain\Authorization;

/**
 * Institution-mail policy — port of pre-laravel-final-state
 * `app/core/email_policy.py`. Subdomains of an allowed domain match;
 * lookalikes (`pdn.ac.lk.evil.com`) do not (dot boundary required).
 */
final class EmailPolicy
{
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function domain(string $email): string
    {
        $parts = self::split($email);

        return $parts === null ? '' : $parts[1];
    }

    /**
     * @param  list<string>|null  $domains
     */
    public static function isInstitution(string $email, ?array $domains = null): bool
    {
        $allowed = array_map(
            static fn (string $domain): string => strtolower(ltrim(trim($domain), '@')),
            $domains ?? self::allowedDomains(),
        );

        $parts = self::split($email);

        if ($parts === null) {
            return false;
        }

        foreach ($allowed as $domain) {
            if ($parts[1] === $domain || str_ends_with($parts[1], '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    public static function error(): string
    {
        return 'Institution email required (allowed: '.implode(', ', self::allowedDomains()).')';
    }

    /**
     * @return list<string>
     */
    private static function allowedDomains(): array
    {
        $raw = (string) config('blitto.allowed_email_domains', 'pdn.ac.lk');

        return array_values(array_filter(
            array_map(
                static fn (string $domain): string => strtolower(ltrim(trim($domain), '@')),
                explode(',', $raw),
            ),
            static fn (string $domain): bool => $domain !== '',
        ));
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function split(string $email): ?array
    {
        $address = self::normalize($email);

        if (substr_count($address, '@') !== 1) {
            return null;
        }

        [$local, $domain] = explode('@', $address, 2);

        if ($local === '' || $domain === '' || preg_match('/\s/', $address) === 1) {
            return null;
        }

        return [$local, $domain];
    }
}
