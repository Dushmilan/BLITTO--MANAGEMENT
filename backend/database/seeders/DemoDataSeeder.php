<?php

namespace Database\Seeders;

use App\Domain\Authorization\Role;
use App\Domain\Authorization\UserDirectory;
use App\Domain\Vault\DocumentService;
use App\Domain\Vault\DocumentStore;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Inventor;
use App\Models\Notification;
use App\Models\StatusHistory;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Restores the reference demo state from dev-data/seed_snapshot.json (spec §9).
 * Rows keep the snapshot's own uuids, so a second run is a no-op.
 */
final class DemoDataSeeder extends Seeder
{
    public const int SUPPORTED_VERSION = 1;

    public function run(): void
    {
        $this->import($this->loadSnapshot());
    }

    /** @param array<string, mixed> $snapshot */
    public function import(array $snapshot): void
    {
        if (($snapshot['version'] ?? null) !== self::SUPPORTED_VERSION) {
            throw new RuntimeException('Unsupported demo snapshot version: '.json_encode($snapshot['version'] ?? null));
        }

        $authorization = (array) ($snapshot['authorization'] ?? []);
        $overrides = $this->demoOverrides((array) ($authorization['users'] ?? []));

        $emails = $this->importUsers($authorization, $overrides);
        $applications = $this->importApplications((array) ($snapshot['application_intake'] ?? []), $emails, $overrides);

        $this->importDeadlines((array) ($snapshot['docketing'] ?? []));
        $this->importNotifications((array) ($snapshot['notification'] ?? []));
        $this->importAuditRows((array) ($snapshot['audit'] ?? []));
        $this->importDocuments((array) ($snapshot['document_vault'] ?? []));

        $this->command?->info(sprintf(
            'Demo data: %d user(s), %d application(s).',
            count($emails),
            $applications,
        ));
    }

    /**
     * The configured demo inventors displace the snapshot's user-role accounts
     * in order, so seeded applications follow the demo configuration.
     *
     * @param  array<string, mixed>  $rows
     * @return array<string, array{email: string, password: ?string, name: string}>
     */
    private function demoOverrides(array $rows): array
    {
        $inventorIds = [];

        foreach ($rows as $id => $row) {
            if (($row['role'] ?? null) === Role::USER->value) {
                $inventorIds[] = (string) $id;
            }
        }

        $overrides = [];

        foreach ([[1, 'demo_inventor1_'], [2, 'demo_inventor2_']] as [$index, $prefix]) {
            $email = (string) config("blitto.{$prefix}email", '');

            if ($email === '' || ! isset($inventorIds[$index - 1])) {
                continue;
            }

            $overrides[$inventorIds[$index - 1]] = [
                'email' => $email,
                'password' => (string) config("blitto.{$prefix}password", '') ?: null,
                'name' => (string) config("blitto.{$prefix}name", ''),
            ];
        }

        return $overrides;
    }

    /** @return array<string, mixed> */
    private function loadSnapshot(): array
    {
        $configured = (string) config('blitto.demo_snapshot_path');
        $path = str_starts_with($configured, '/') ? $configured : base_path($configured);

        if (! is_file($path)) {
            throw new RuntimeException("Demo snapshot not found at {$path}.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Demo snapshot at {$path} is not valid JSON.");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $authorization
     * @param  array<string, array{email: string, password: ?string, name: string}>  $overrides
     * @return array<string, string> snapshot id => the email recorded in the snapshot
     */
    private function importUsers(array $authorization, array $overrides): array
    {
        $rows = (array) ($authorization['users'] ?? []);
        $passwords = (array) ($authorization['passwords'] ?? []);
        $directory = app(UserDirectory::class);
        $emails = [];

        foreach ($rows as $id => $row) {
            $override = $overrides[(string) $id] ?? null;
            $snapshotEmail = (string) ($row['email'] ?? '');
            $email = $override['email'] ?? $snapshotEmail;
            $password = $override['password'] ?? (isset($passwords[$id]) ? (string) $passwords[$id] : null);

            if ($directory->findByEmail($email) === null) {
                $directory->register(
                    email: $email,
                    role: Role::from((string) ($row['role'] ?? 'user')),
                    password: $password,
                    userCode: isset($row['user_code']) ? (string) $row['user_code'] : null,
                );
            }

            // Keyed by the snapshot's own email: the application rows in the
            // file carry that address, so this is what the caller can match on.
            $emails[(string) $id] = $snapshotEmail;
        }

        return $emails;
    }

    /**
     * @param  array<string, mixed>  $intake
     * @param  array<string, string>  $emails
     */
    /**
     * @param  array<string, mixed>  $intake
     * @param  array<string, string>  $emails  snapshot id => the email recorded in the snapshot
     * @param  array<string, array{email: string, password: ?string, name: string}>  $overrides
     */
    private function importApplications(array $intake, array $emails, array $overrides): int
    {
        $store = (array) ($intake['store'] ?? []);
        $history = (array) ($intake['history'] ?? []);
        $emailMap = [];
        $nameMap = [];

        foreach ($emails as $id => $snapshotEmail) {
            $emailMap[$snapshotEmail] = $overrides[$id]['email'] ?? $snapshotEmail;

            if (($overrides[$id]['name'] ?? '') !== '') {
                $nameMap[$snapshotEmail] = $overrides[$id]['name'];
            }
        }

        $count = 0;

        foreach ($store as $id => $row) {
            $id = (string) $id;
            $owner = (string) ($row['inventor_email'] ?? '');

            $application = Application::query()->find($id) ?? new Application;
            $application->id = $id;
            $application->fill([
                'title' => (string) ($row['title'] ?? ''),
                'inventor_name' => $nameMap[$owner] ?? (string) ($row['inventor_name'] ?? ''),
                'inventor_email' => $emailMap[$owner] ?? $owner,
                'status' => (string) ($row['status'] ?? 'DRAFT'),
                'application_number' => $row['application_number'] ?? null,
                'nipo_reference' => $row['nipo_reference'] ?? null,
                'technology_area' => (string) ($row['technology_area'] ?? ''),
            ]);
            $application->created_at = $row['created_at'] ?? null;
            $application->updated_at = $row['updated_at'] ?? ($row['created_at'] ?? null);
            $application->save();

            foreach ((array) ($row['inventors'] ?? []) as $inventor) {
                $nested = (string) ($inventor['inventor_email'] ?? '');

                Inventor::query()->updateOrCreate(
                    ['application_id' => $id, 'inventor_email' => $emailMap[$nested] ?? $nested],
                    ['inventor_name' => $nameMap[$nested] ?? (string) ($inventor['inventor_name'] ?? '')],
                );
            }

            foreach ((array) ($history[$id] ?? []) as $entry) {
                $entryRow = StatusHistory::query()->find((string) $entry['id']) ?? new StatusHistory;
                $entryRow->id = (string) $entry['id'];
                $entryRow->fill([
                    'application_id' => (string) $entry['application_id'],
                    'old_status' => $entry['old_status'] ?? null,
                    'new_status' => (string) $entry['new_status'],
                    'changed_by' => (string) $entry['changed_by'],
                    // The snapshot predates status_history.reason, which is NOT
                    // NULL with no default.
                    'reason' => '',
                ]);
                $entryRow->changed_at = $entry['changed_at'] ?? null;
                $entryRow->save();
            }

            $count++;
        }

        return $count;
    }

    /** @param array<string, mixed> $docketing */
    private function importDeadlines(array $docketing): void
    {
        foreach ((array) ($docketing['deadlines'] ?? []) as $id => $row) {
            $deadline = Deadline::query()->find((string) $id) ?? new Deadline;
            $deadline->id = (string) $id;
            $deadline->fill([
                'application_id' => (string) $row['application_id'],
                'type' => (string) $row['type'],
                'due_date' => $row['due_date'],
                'status' => (string) ($row['status'] ?? 'OPEN'),
            ]);
            $deadline->created_at = $row['created_at'] ?? null;
            $deadline->save();
        }
    }

    /** @param array<string, mixed> $notification */
    private function importNotifications(array $notification): void
    {
        foreach ((array) ($notification['sent'] ?? []) as $row) {
            $record = Notification::query()->find((string) $row['id']) ?? new Notification;
            $record->id = (string) $row['id'];
            $record->fill([
                'recipient_email' => (string) $row['recipient_email'],
                'subject' => (string) $row['subject'],
                'body' => (string) $row['body'],
                'kind' => (string) ($row['kind'] ?? 'status_change'),
            ]);
            $record->sent_at = $row['sent_at'] ?? null;
            $record->save();
        }
    }

    /** @param array<string, mixed> $audit */
    private function importAuditRows(array $audit): void
    {
        foreach ((array) ($audit['log'] ?? []) as $id => $row) {
            $log = AuditLog::query()->find((string) $id) ?? new AuditLog;
            $log->id = (string) $id;
            $log->fill([
                'user_id' => $row['user_id'] ?? null,
                'application_id' => $row['application_id'] ?? null,
                'action' => (string) $row['action'],
                'ip_address' => $row['ip_address'] ?? null,
            ]);
            $log->timestamp = $row['timestamp'] ?? null;
            $log->save();
        }
    }

    /** @param array<string, mixed> $vault */
    private function importDocuments(array $vault): void
    {
        $contents = (array) ($vault['contents'] ?? []);
        $documents = app(DocumentService::class);
        $store = app(DocumentStore::class);

        foreach ((array) ($vault['documents'] ?? []) as $id => $row) {
            $id = (string) $id;
            $existing = Document::query()->find($id);

            if ($existing !== null && $documents->tryBytesFor($existing) !== null) {
                continue;
            }

            $encoded = $contents[(string) ($row['content_ref'] ?? '')] ?? null;
            $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;

            if ($bytes === false || $bytes === '') {
                $this->command?->warn("Demo data: skipping document {$id} (no readable content).");

                continue;
            }

            $document = $existing ?? new Document;
            $document->id = $id;
            $document->fill([
                'application_id' => (string) $row['application_id'],
                'filename' => (string) $row['filename'],
                'file_size' => strlen($bytes),
                'content_ref' => $store->put($bytes),
                'uploaded_by' => (string) ($row['uploaded_by'] ?? ''),
            ]);
            $document->uploaded_at = $row['uploaded_at'] ?? null;
            $document->save();
        }
    }
}
