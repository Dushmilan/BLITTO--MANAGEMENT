<?php

namespace Database\Seeders;

use App\Domain\Authorization\Role;
use App\Domain\Authorization\UserDirectory;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Deadline;
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

        $emails = $this->importUsers((array) ($snapshot['authorization'] ?? []));
        $applications = $this->importApplications((array) ($snapshot['application_intake'] ?? []), $emails);

        $this->importDeadlines((array) ($snapshot['docketing'] ?? []));
        $this->importNotifications((array) ($snapshot['notification'] ?? []));
        $this->importAuditRows((array) ($snapshot['audit'] ?? []));

        $this->command?->info(sprintf(
            'Demo data: %d user(s), %d application(s).',
            count($emails),
            $applications,
        ));
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
     * @return array<string, string> snapshot id => stored email
     */
    private function importUsers(array $authorization): array
    {
        $rows = (array) ($authorization['users'] ?? []);
        $passwords = (array) ($authorization['passwords'] ?? []);
        $directory = app(UserDirectory::class);
        $emails = [];

        foreach ($rows as $id => $row) {
            $email = (string) ($row['email'] ?? '');

            if ($directory->findByEmail($email) === null) {
                $directory->register(
                    email: $email,
                    role: Role::from((string) ($row['role'] ?? 'user')),
                    password: isset($passwords[$id]) ? (string) $passwords[$id] : null,
                    userCode: isset($row['user_code']) ? (string) $row['user_code'] : null,
                );
            }

            $emails[(string) $id] = $email;
        }

        return $emails;
    }

    /**
     * @param  array<string, mixed>  $intake
     * @param  array<string, string>  $emails
     */
    private function importApplications(array $intake, array $emails): int
    {
        $store = (array) ($intake['store'] ?? []);
        $history = (array) ($intake['history'] ?? []);
        $count = 0;

        foreach ($store as $id => $row) {
            $id = (string) $id;

            $application = Application::query()->find($id) ?? new Application;
            $application->id = $id;
            $application->fill([
                'title' => (string) ($row['title'] ?? ''),
                'inventor_name' => (string) ($row['inventor_name'] ?? ''),
                'inventor_email' => (string) ($row['inventor_email'] ?? ''),
                'status' => (string) ($row['status'] ?? 'DRAFT'),
                'application_number' => $row['application_number'] ?? null,
                'nipo_reference' => $row['nipo_reference'] ?? null,
                'technology_area' => (string) ($row['technology_area'] ?? ''),
            ]);
            $application->created_at = $row['created_at'] ?? null;
            $application->updated_at = $row['updated_at'] ?? ($row['created_at'] ?? null);
            $application->save();

            foreach ((array) ($row['inventors'] ?? []) as $inventor) {
                Inventor::query()->updateOrCreate(
                    ['application_id' => $id, 'inventor_email' => (string) ($inventor['inventor_email'] ?? '')],
                    ['inventor_name' => (string) ($inventor['inventor_name'] ?? '')],
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
}
