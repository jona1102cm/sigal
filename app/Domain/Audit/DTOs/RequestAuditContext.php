<?php

namespace App\Domain\Audit\DTOs;

use Illuminate\Http\Request;

readonly class RequestAuditContext
{
    public function __construct(
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?int $actingLegislatureMemberId = null,
        public ?int $actingBodyMembershipId = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            actingLegislatureMemberId: $request->attributes->getInt('acting_legislature_member_id') ?: null,
            actingBodyMembershipId: $request->attributes->getInt('acting_body_membership_id') ?: null,
        );
    }
}
