<?php

namespace App\Domain\ApplicationIntake;

use App\Domain\Authorization\EmailPolicy;

/**
 * The intake payload — port of the FastAPI `Disclosure` model
 * (`app/modules/application_intake/models.py`), minus the Pydantic
 * validators that the controller now expresses as Laravel rules.
 */
final class Disclosure
{
    /**
     * @param  list<array{inventor_name: string, inventor_email: string}>  $inventors
     */
    public function __construct(
        public string $title,
        public string $inventorName = '',
        public string $inventorEmail = '',
        public array $inventors = [],
        public string $technologyArea = '',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array{inventor_name: string, inventor_email: string}> $inventors */
        $inventors = array_values(array_map(
            static fn (array $inventor): array => [
                'inventor_name' => trim((string) $inventor['inventor_name']),
                'inventor_email' => EmailPolicy::normalize((string) $inventor['inventor_email']),
            ],
            $data['inventors'] ?? [],
        ));

        return new self(
            title: trim((string) $data['title']),
            inventorName: trim((string) ($data['inventor_name'] ?? '')),
            inventorEmail: EmailPolicy::normalize((string) ($data['inventor_email'] ?? '')),
            inventors: $inventors,
            technologyArea: trim((string) ($data['technology_area'] ?? '')),
        );
    }

    /**
     * One application has one main author for display purposes; the
     * `inventors` list is the multi-inventor source of truth.
     *
     * @return list<array{inventor_name: string, inventor_email: string}>
     */
    public function resolvedInventors(): array
    {
        if ($this->inventors !== []) {
            return $this->inventors;
        }

        return [[
            'inventor_name' => $this->inventorName,
            'inventor_email' => $this->inventorEmail,
        ]];
    }
}
