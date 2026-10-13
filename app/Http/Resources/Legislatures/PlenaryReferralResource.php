<?php

namespace App\Http\Resources\Legislatures;

use App\Http\Resources\DocumentManagement\ExpedientResource;
use App\Models\PlenaryReferral;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlenaryReferral */
class PlenaryReferralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legislature' => $this->whenLoaded('legislature', fn () => [
                'id' => $this->legislature->id,
                'period_label' => $this->legislature->period_label,
            ]),
            'expedient' => $this->whenLoaded(
                'expedient',
                fn () => new ExpedientResource($this->expedient),
            ),
            'referred_by' => $this->whenLoaded('referredBy', fn () => [
                'id' => $this->referredBy->id,
                'name' => $this->referredBy->name,
            ]),
            'referred_at' => $this->referred_at->toIso8601String(),
            'concluded_at' => $this->concluded_at?->toIso8601String(),
        ];
    }
}
