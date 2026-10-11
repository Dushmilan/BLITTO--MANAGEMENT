<?php

it('provides app settings with parity defaults', function () {
    expect(config('blitto.environment'))->toBe('local')
        ->and(config('blitto.allowed_email_domains'))->toBe('pdn.ac.lk')
        ->and(config('blitto.nipo_follow_up_interval_hours'))->toBe(24)
        ->and(config('blitto.document_store'))->toBe('local')
        ->and(config('blitto.vault_cert_dir'))->toBe('certs')
        ->and(config('blitto.vault_storage_dir'))->toBe('encrypted_docs')
        ->and(config('blitto.vault_ttl_hours'))->toBe(12)
        ->and(config('blitto.storage'))->toBe('memory')
        ->and(config('blitto.sqlite_path'))->toBe('dev-data/state.sqlite3')
        ->and(config('blitto.auth_secret'))->toBe('change-me-in-production-32byte-minimum!');
});

it('reads settings from BLITTODB_-prefixed env vars', function () {
    putenv('BLITTODB_VAULT_TTL_HOURS=6');
    $_ENV['BLITTODB_VAULT_TTL_HOURS'] = '6';

    $this->refreshApplication();

    expect(config('blitto.vault_ttl_hours'))->toBe(6);
});

afterEach(function () {
    putenv('BLITTODB_VAULT_TTL_HOURS');
    unset($_ENV['BLITTODB_VAULT_TTL_HOURS']);
});
