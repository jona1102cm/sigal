<?php

use App\Domain\Legislatures\Enums\BoardPosition;
use App\Domain\Legislatures\Enums\LegislatureStatus;

test('legislature statuses expose their institutional labels', function () {
    expect(LegislatureStatus::Active->label())->toBe('Activa')
        ->and(LegislatureStatus::Inactive->label())->toBe('Inactiva');
});

test('the board positions match the approved composition', function () {
    expect(array_map(
        fn (BoardPosition $position) => $position->label(),
        BoardPosition::cases(),
    ))->toBe([
        'Presidente',
        'Vicepresidente',
        'Segundo Vicepresidente',
        'Secretaria',
        'Segunda Secretaria',
    ]);
});
