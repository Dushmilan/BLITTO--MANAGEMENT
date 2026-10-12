<?php

namespace App\Support;

use RuntimeException;

/**
 * Fail fast on insecure production config. Session auth signs with APP_KEY
 * (the FastAPI-era JWT `auth_secret` is gone), so an empty key outside local
 * is the only hard requirement left here.
 */
final class ProductionReadiness
{
    public function __construct(
        private readonly string $environment,
        private readonly ?string $appKey,
    ) {}

    public function assert(): void
    {
        if (strtolower($this->environment) === 'local') {
            return;
        }

        if ($this->appKey === null || trim($this->appKey) === '') {
            throw new RuntimeException('APP_KEY must be set when BLITTODB_ENVIRONMENT is not local.');
        }
    }
}
