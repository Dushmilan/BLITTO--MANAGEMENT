<?php

/*
|--------------------------------------------------------------------------
| BLITTO PATENT DATABASE — application settings
|--------------------------------------------------------------------------
| Renamed from the FastAPI-era BLITTO_* env prefix to BLITTODB_*. Keys and
| defaults mirror the original pydantic Settings (app/core/config.py) one
| for one; see the pre-laravel-final-state git tag for the source of truth.
*/

return [

    'service_name' => env('BLITTODB_SERVICE_NAME', 'blitto-patent-database'),
    'environment' => env('BLITTODB_ENVIRONMENT', 'local'),

    // better-auth production adapter (JWT/JWKS verification). Unused locally.
    'better_auth_jwks_url' => env('BLITTODB_BETTER_AUTH_JWKS_URL', ''),
    'better_auth_issuer' => env('BLITTODB_BETTER_AUTH_ISSUER', ''),
    'better_auth_audience' => env('BLITTODB_BETTER_AUTH_AUDIENCE', ''),

    // Secret for the local-stub auth adapter (better-auth handles secrets in prod).
    'auth_secret' => env('BLITTODB_AUTH_SECRET', 'change-me-in-production-32byte-minimum!'),

    // Institution-mail gate: only these domains may register/log in.
    // Env: BLITTODB_ALLOWED_EMAIL_DOMAINS="pdn.ac.lk,sci.pdn.ac.lk" (comma-separated).
    'allowed_email_domains' => env('BLITTODB_ALLOWED_EMAIL_DOMAINS', 'pdn.ac.lk'),

    // Optional first-admin bootstrap so the system is usable out-of-box.
    'bootstrap_admin_email' => env('BLITTODB_BOOTSTRAP_ADMIN_EMAIL', ''),
    'bootstrap_admin_password' => env('BLITTODB_BOOTSTRAP_ADMIN_PASSWORD', ''),

    // Demo inventor accounts for local development.
    'demo_inventor1_email' => env('BLITTODB_DEMO_INVENTOR1_EMAIL', ''),
    'demo_inventor1_password' => env('BLITTODB_DEMO_INVENTOR1_PASSWORD', ''),
    'demo_inventor1_name' => env('BLITTODB_DEMO_INVENTOR1_NAME', ''),
    'demo_inventor2_email' => env('BLITTODB_DEMO_INVENTOR2_EMAIL', ''),
    'demo_inventor2_password' => env('BLITTODB_DEMO_INVENTOR2_PASSWORD', ''),
    'demo_inventor2_name' => env('BLITTODB_DEMO_INVENTOR2_NAME', ''),

    // How often (in hours) to check for unacknowledged filings and notify admin.
    'nipo_follow_up_interval_hours' => (int) env('BLITTODB_NIPO_FOLLOW_UP_INTERVAL_HOURS', 24),

    // Vault master key (provided via env/secret manager; never persisted by the app).
    'vault_master_key' => env('BLITTODB_VAULT_MASTER_KEY', ''),
    'document_store' => env('BLITTODB_DOCUMENT_STORE', 'local'),   // "local" | "pki"
    'vault_cert_dir' => env('BLITTODB_VAULT_CERT_DIR', 'certs'),
    'vault_storage_dir' => env('BLITTODB_VAULT_STORAGE_DIR', 'encrypted_docs'),
    'vault_ttl_hours' => (int) env('BLITTODB_VAULT_TTL_HOURS', 12),

    // Persistence seam — Laravel/SQLite supersedes this at L1; kept for parity.
    'storage' => env('BLITTODB_STORAGE', 'memory'),
    'sqlite_path' => env('BLITTODB_SQLITE_PATH', 'dev-data/state.sqlite3'),

];
