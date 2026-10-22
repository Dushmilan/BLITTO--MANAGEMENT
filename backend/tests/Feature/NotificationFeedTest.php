<?php

use App\Domain\Notification\NotificationFeed;
use App\Models\Notification;
use App\Models\User;

function makeNotification(string $email, string $subject, string $sentAt): Notification
{
    $notification = new Notification([
        'recipient_email' => $email,
        'subject' => $subject,
        'body' => 'Body of '.$subject,
        'kind' => 'status_change',
    ]);
    $notification->sent_at = $sentAt;
    $notification->save();

    return $notification;
}

it('returns the viewer’s notifications newest first, and only theirs', function () {
    makeNotification('mine@pdn.ac.lk', 'Older', '2026-01-01 00:00:00');
    makeNotification('mine@pdn.ac.lk', 'Newer', '2026-02-01 00:00:00');
    makeNotification('theirs@pdn.ac.lk', 'Not Mine', '2026-03-01 00:00:00');

    $inventor = User::factory()->create(['email' => 'mine@pdn.ac.lk']);

    $this->actingAs($inventor)
        ->getJson('/notifications')
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.subject', 'Newer')
        ->assertJsonPath('1.subject', 'Older')
        ->assertJsonPath('0.recipient_email', 'mine@pdn.ac.lk')
        ->assertJsonStructure([['id', 'recipient_email', 'subject', 'body', 'kind', 'sent_at']]);
});

it('caps the payload at one hundred rows', function () {
    foreach (range(1, 105) as $index) {
        makeNotification('mine@pdn.ac.lk', sprintf('Row %03d', $index), sprintf('2026-01-01 00:%02d:00', $index % 60));
    }

    $inventor = User::factory()->create(['email' => 'mine@pdn.ac.lk']);

    $this->actingAs($inventor)->getJson('/notifications')->assertOk()->assertJsonCount(100);
});

it('counts the viewer’s notifications', function () {
    makeNotification('mine@pdn.ac.lk', 'One', '2026-01-01 00:00:00');
    makeNotification('mine@pdn.ac.lk', 'Two', '2026-01-02 00:00:00');
    makeNotification('theirs@pdn.ac.lk', 'Three', '2026-01-03 00:00:00');

    $feed = app(NotificationFeed::class);

    expect($feed->countFor(User::factory()->create(['email' => 'mine@pdn.ac.lk'])))->toBe(2);
});

it('requires authentication', function () {
    $this->getJson('/notifications')->assertUnauthorized();
});
