<?php

use App\Domain\Vault\DocumentStore;
use App\Domain\Vault\UnsupportedDocumentStore;
use Illuminate\Support\Facades\Storage;

it('stores, retrieves and deletes document bytes', function () {
    Storage::fake('vault');

    $store = app(DocumentStore::class);
    $ref = $store->put('patent bytes');

    expect($ref)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($store->get($ref))->toBe('patent bytes')
        ->and($store->get('11111111-1111-1111-1111-111111111111'))->toBeNull()
        ->and($store->delete($ref))->toBeTrue()
        ->and($store->get($ref))->toBeNull()
        ->and($store->delete($ref))->toBeFalse();
});

it('refuses refs that are not opaque identifiers', function () {
    Storage::fake('vault');

    $store = app(DocumentStore::class);

    expect($store->get('../private_key.enc'))->toBeNull()
        ->and($store->get('../../etc/passwd'))->toBeNull()
        ->and($store->get('not-a-uuid'))->toBeNull()
        ->and($store->delete('../escape'))->toBeFalse();
});

it('fails loudly when the encrypted store is requested', function () {
    config(['blitto.document_store' => 'pki']);
    $this->app->forgetInstance(DocumentStore::class);

    expect(fn () => app(DocumentStore::class))->toThrow(UnsupportedDocumentStore::class);
});
