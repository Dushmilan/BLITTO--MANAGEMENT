<?php

use App\Models\Notification;
use App\Models\User;

it('lists the viewer’s notifications newest first with their kind', function () {
    $inventor = User::factory()->create(['email' => 'mine@pdn.ac.lk']);

    foreach ([['Older', '2026-01-01 00:00:00'], ['Newer', '2026-02-01 00:00:00']] as [$subject, $sentAt]) {
        $notification = new Notification([
            'recipient_email' => 'mine@pdn.ac.lk',
            'subject' => $subject,
            'body' => 'Body of '.$subject,
            'kind' => 'download_ready',
        ]);
        $notification->sent_at = $sentAt;
        $notification->save();
    }

    Notification::query()->create([
        'recipient_email' => 'theirs@pdn.ac.lk',
        'subject' => 'Not Mine',
        'body' => 'Body',
        'kind' => 'status_change',
    ]);

    $this->actingAs($inventor)
        ->get('/user/notifications')
        ->assertOk()
        ->assertSeeInOrder(['Newer', 'Older'])
        ->assertSee('download_ready')
        ->assertDontSee('Not Mine');
});

it('shows the empty state', function () {
    $this->actingAs(User::factory()->create())
        ->get('/user/notifications')
        ->assertOk()
        ->assertSee('No notifications yet.');
});

it('shows the Notifications nav item last for an inventor', function () {
    $this->actingAs(User::factory()->create())
        ->get('/user')
        ->assertOk()
        ->assertSeeInOrder(['Overview', 'My Patents', 'Notifications'])
        ->assertSee('>Notifications</a>', false);
});

it('counts the notifications on the overview', function () {
    $inventor = User::factory()->create(['email' => 'mine@pdn.ac.lk']);
    Notification::query()->create([
        'recipient_email' => 'mine@pdn.ac.lk',
        'subject' => 'One',
        'body' => 'Body',
        'kind' => 'status_change',
    ]);

    $this->actingAs($inventor)
        ->get('/user')
        ->assertOk()
        ->assertSee('data-notification-count class="sr-only">1', false);
});
