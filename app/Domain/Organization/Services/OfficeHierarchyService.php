<?php

namespace App\Domain\Organization\Services;

use App\Models\Office;
use Illuminate\Support\Collection;

/** Resuelve descendientes desde la relación parent_id sin depender del orden del organigrama. */
class OfficeHierarchyService
{
    /** @param iterable<int, int|string> $rootIds @return Collection<int, int> */
    public function expandWithDescendants(iterable $rootIds): Collection
    {
        $roots = collect($rootIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($roots->isEmpty()) {
            return collect();
        }

        $childrenByParent = Office::query()->get(['id', 'parent_id'])
            ->groupBy(fn (Office $office) => $office->parent_id === null ? 0 : (int) $office->parent_id);
        $expanded = collect();
        $pending = $roots->all();

        while ($pending !== []) {
            $officeId = (int) array_shift($pending);
            if ($expanded->contains($officeId)) {
                continue;
            }

            $expanded->push($officeId);
            foreach ($childrenByParent->get($officeId, collect()) as $child) {
                $pending[] = (int) $child->id;
            }
        }

        return $expanded->values();
    }
}
