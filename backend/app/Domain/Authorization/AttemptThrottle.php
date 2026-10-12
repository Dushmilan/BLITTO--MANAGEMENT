<?php

namespace App\Domain\Authorization;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Sliding-window attempt limiter for credential endpoints, mirroring the
 * FastAPI-era guards (login per account, vault unlock per IP):
 * 5 failures within 10 minutes, cleared on success.
 */
final class AttemptThrottle
{
    public const string LOGIN = 'login';

    public const string VAULT_UNLOCK = 'vault-unlock';

    public const int MAX_ATTEMPTS = 5;

    public const int DECAY_SECONDS = 600;

    public function locked(string $scope, string $subject): bool
    {
        return RateLimiter::tooManyAttempts($this->key($scope, $subject), self::MAX_ATTEMPTS);
    }

    public function recordFailure(string $scope, string $subject): void
    {
        RateLimiter::hit($this->key($scope, $subject), self::DECAY_SECONDS);
    }

    public function clear(string $scope, string $subject): void
    {
        RateLimiter::clear($this->key($scope, $subject));
    }

    public function retryAfter(string $scope, string $subject): int
    {
        return RateLimiter::availableIn($this->key($scope, $subject));
    }

    private function key(string $scope, string $subject): string
    {
        return $scope.':'.strtolower($subject);
    }
}
