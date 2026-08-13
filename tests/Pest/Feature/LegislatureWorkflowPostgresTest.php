<?php

use App\Domain\Authorization\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Facades\DB;

test('a superadministrator can activate a new legislature and replace board members', function () {
    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('Requiere PostgreSQL disponible en la base de pruebas sigal_test.');
    }

    $this->artisan('migrate:fresh', ['--seed' => true])->assertExitCode(0);

    $superAdministrator = User::factory()->create();
    $role = Role::query()->where('code', RoleCode::SuperAdministrator->value)->sole();

    UserRoleAssignment::query()->create([
        'user_id' => $superAdministrator->id,
        'role_id' => $role->id,
        'assigned_by' => $superAdministrator->id,
        'effective_from' => now()->subMinute(),
    ]);

    $this->actingAs($superAdministrator, 'sanctum');

    $this->postJson('/api/legislatures', [
        'start_year' => 2026,
        'end_year' => 2027,
        'status' => 'active',
    ])->assertCreated();

    $this->postJson('/api/legislatures', [
        'start_year' => 2027,
        'end_year' => 2028,
        'status' => 'active',
    ])->assertCreated();

    expect(DB::table('legislatures')->where('status', 'active')->count())->toBe(1)
        ->and(DB::table('legislatures')->where('start_year', 2026)->value('status'))->toBe('inactive');

    $legislatureId = DB::table('legislatures')->where('status', 'active')->value('id');
    $holder = User::factory()->create();
    $replacement = User::factory()->create();

    $this->postJson("/api/legislatures/{$legislatureId}/board-assignments", [
        'user_id' => $holder->id,
        'position' => 'president',
        'effective_on' => '2027-01-01',
        'effective_at' => '2027-01-01T09:00:00-04:00',
    ])->assertCreated();

    $this->postJson("/api/legislatures/{$legislatureId}/board-assignments", [
        'user_id' => $replacement->id,
        'position' => 'president',
        'effective_on' => '2027-06-01',
        'effective_at' => '2027-06-01T09:00:00-04:00',
    ])->assertCreated();

    $this->assertDatabaseCount('legislature_board_assignments', 2);
    $this->assertDatabaseHas('legislature_board_assignments', [
        'user_id' => $holder->id,
        'position' => 'president',
        'ended_on' => '2027-06-01',
    ]);
    $this->assertDatabaseHas('legislature_board_assignments', [
        'user_id' => $holder->id,
        'position' => 'president',
        'ended_at' => '2027-06-01 13:00:00+00',
    ]);
});
