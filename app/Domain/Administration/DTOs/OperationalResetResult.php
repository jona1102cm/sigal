<?php

namespace App\Domain\Administration\DTOs;

readonly class OperationalResetResult
{
    /** @param array<string, int> $removed */
    public function __construct(
        public array $removed,
        public int $filesDeleted,
        public int $filesPendingDeletion,
    ) {}

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            ...$this->removed,
            'files_deleted' => $this->filesDeleted,
            'files_pending_deletion' => $this->filesPendingDeletion,
        ];
    }
}
