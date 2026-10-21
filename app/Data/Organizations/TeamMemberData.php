<?php

namespace App\Data\Organizations;

/**
 * Immutable input for organization membership changes.
 * Values are taken from validated request data, never directly from raw input.
 */
final readonly class TeamMemberData
{
    /** @param list<int> $branchIds */
    public function __construct(
        public string $role,
        public array $branchIds,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            role: (string) $validated['role'],
            branchIds: array_values(array_unique(array_map('intval', $validated['branch_ids'] ?? []))),
        );
    }
}
