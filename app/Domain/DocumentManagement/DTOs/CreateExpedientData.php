<?php

namespace App\Domain\DocumentManagement\DTOs;

use App\Domain\DocumentManagement\Enums\ExpedientOrigin;
use App\Domain\DocumentManagement\Enums\ExpedientPriority;
use App\Domain\DocumentManagement\Enums\SenderType;
use Carbon\CarbonImmutable;

readonly class CreateExpedientData
{
    public function __construct(
        public int $expedientTypeId,
        public ?int $confidentialityLevelId,
        public string $subject,
        public ?string $summary,
        public ExpedientOrigin $origin,
        public ?SenderType $senderType,
        public ?string $senderName,
        public ?int $originOfficeId,
        public int $responsibleOfficeId,
        public CarbonImmutable $receivedOn,
        public ExpedientPriority $priority,
        public ?CarbonImmutable $dueOn,
        public ?string $classification,
        public ?string $observations,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            expedientTypeId: (int) $validated['expedient_type_id'],
            confidentialityLevelId: isset($validated['confidentiality_level_id']) ? (int) $validated['confidentiality_level_id'] : null,
            subject: $validated['subject'],
            summary: $validated['summary'] ?? null,
            origin: ExpedientOrigin::from($validated['origin']),
            senderType: isset($validated['sender_type']) ? SenderType::from($validated['sender_type']) : null,
            senderName: $validated['sender_name'] ?? null,
            originOfficeId: isset($validated['origin_office_id']) ? (int) $validated['origin_office_id'] : null,
            responsibleOfficeId: (int) $validated['responsible_office_id'],
            receivedOn: CarbonImmutable::parse($validated['received_on']),
            priority: ExpedientPriority::from($validated['priority']),
            dueOn: isset($validated['due_on']) ? CarbonImmutable::parse($validated['due_on']) : null,
            classification: $validated['classification'] ?? null,
            observations: $validated['observations'] ?? null,
        );
    }
}
