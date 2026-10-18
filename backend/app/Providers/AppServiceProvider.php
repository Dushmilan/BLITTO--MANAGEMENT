<?php

namespace App\Providers;

use App\Domain\Authorization\BootstrapAdmin;
use App\Domain\Authorization\InvalidInstitutionEmail;
use App\Domain\Vault\DocumentStore;
use App\Domain\Vault\LocalDocumentStore;
use App\Domain\Vault\UnsupportedDocumentStore;
use App\Support\ProductionReadiness;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DocumentStore::class, static function (): DocumentStore {
            $configured = (string) config('blitto.document_store');

            return match ($configured) {
                'local' => new LocalDocumentStore(Storage::disk('vault')),
                default => throw new UnsupportedDocumentStore(
                    "Document store [{$configured}] is not available; only \"local\" is implemented.",
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fail closed: only trust BLITTODB_ENVIRONMENT as "local" when the app
        // itself is local/testing (APP_ENV). A production deploy with the
        // BLITTO var unset must not resolve to local and skip the readiness guard.
        $environment = app()->environment(['local', 'testing'])
            ? (string) config('blitto.environment', 'local')
            : 'production';

        (new ProductionReadiness($environment, config('app.key')))->assert();

        // Web requests only: console runs (migrate, tests) skip the bootstrap so
        // no query hits `users` before it exists. BootstrapAdmin is tested directly.
        if (! app()->runningInConsole()) {
            try {
                app(BootstrapAdmin::class)->ensure();
            } catch (InvalidInstitutionEmail $e) {
                throw new RuntimeException(
                    'BLITTODB_BOOTSTRAP_ADMIN_EMAIL must be an institution address: '.$e->getMessage(),
                    previous: $e,
                );
            }
        }
    }
}
