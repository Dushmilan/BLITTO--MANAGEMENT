<?php

namespace App\Domain\Notification;

/**
 * Test adapter: keeps every message in memory so tests can assert delivery
 * through our own seam instead of the framework's mail fake.
 */
final class ArrayMailChannel implements MailChannel
{
    /** @var list<array{to: string, subject: string, body: string}> */
    private array $messages = [];

    public function send(string $to, string $subject, string $body): void
    {
        $this->messages[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }

    /** @return list<array{to: string, subject: string, body: string}> */
    public function messages(): array
    {
        return $this->messages;
    }

    /** @return list<array{to: string, subject: string, body: string}> */
    public function sentTo(string $email): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (array $message): bool => $message['to'] === $email,
        ));
    }

    /** @return array{to: string, subject: string, body: string}|null */
    public function lastMessage(): ?array
    {
        return $this->messages === [] ? null : $this->messages[array_key_last($this->messages)];
    }
}
