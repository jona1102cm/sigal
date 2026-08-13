<?php

namespace App\Domain\Audit\DTOs;

use Illuminate\Http\Request;

readonly class RequestAuditContext
{
    public function __construct(
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }
}
