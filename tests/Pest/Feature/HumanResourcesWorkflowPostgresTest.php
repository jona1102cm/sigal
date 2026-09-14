<?php

use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\HumanResources\Services\EmployeeImportWorkbookReader;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\OfficePosition;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('Requiere PostgreSQL disponible en la base de pruebas sigal_test.');
    }

    $this->artisan('migrate:fresh', ['--seed' => true])->assertExitCode(0);
    Storage::fake('local');
});

function humanResourcesAdministrator(): User
{
    $administrator = User::factory()->create();
    $role = Role::query()->where('code', RoleCode::SuperAdministrator->value)->firstOrFail();
    UserRoleAssignment::query()->create([
        'user_id' => $administrator->id,
        'role_id' => $role->id,
        'assigned_by' => $administrator->id,
        'effective_from' => now(),
    ]);

    return $administrator;
}

function humanResourcesPayload(OfficePosition $position, array $overrides = []): array
{
    return [
        'identity_card' => '8123456',
        'first_names' => 'María Elena',
        'last_names' => 'Vargas Suárez',
        'mobile_phone' => '70000000',
        'birth_date' => '1992-05-10',
        'academic_degree' => 'Licenciatura',
        'profession' => 'Ingeniera de Sistemas',
        'contract_type' => 'eventual',
        'contract_amount' => '5350.50',
        'starts_on' => today()->toDateString(),
        'office_position_id' => $position->id,
        'role' => RoleCode::SimpleUser->value,
        ...$overrides,
    ];
}

/** @param array<int, array<int, string|null>> $rows */
function employeeImportWorkbook(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'sigal-import-test-');
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $xmlRows = [];

    foreach ($rows as $rowIndex => $values) {
        $cells = [];

        foreach ($values as $columnIndex => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $column = '';
            $number = $columnIndex + 1;
            while ($number > 0) {
                $remainder = ($number - 1) % 26;
                $column = chr(65 + $remainder).$column;
                $number = intdiv($number - 1, 26);
            }
            $reference = $column.($rowIndex + 1);
            $cells[] = '<c r="'.$reference.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
        }

        $xmlRows[] = '<row r="'.($rowIndex + 1).'">'.implode('', $cells).'</row>';
    }

    $archive->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.implode('', $xmlRows).'</sheetData></worksheet>');
    $archive->close();
    $contents = file_get_contents($path);
    unlink($path);

    return UploadedFile::fake()->createWithContent('funcionarios.xlsx', $contents);
}

test('a human resources registration creates one employee, account, contract, membership and supporting files', function () {
    $administrator = humanResourcesAdministrator();
    Sanctum::actingAs($administrator);
    $office = Office::query()->create(['code' => 'RRHH-TEST', 'name' => 'Recursos Humanos de prueba']);
    $position = OfficePosition::query()->create([
        'office_id' => $office->id,
        'name' => 'Profesional de Recursos Humanos',
        'membership_role' => 'official',
        'created_by' => $administrator->id,
    ]);

    $response = $this->post('/api/human-resources/employees', [
        ...humanResourcesPayload($position),
        'profile_photo' => UploadedFile::fake()->createWithContent('perfil.jpg', 'foto de perfil inicial'),
        'rejap_certificate' => UploadedFile::fake()->create('rejap.pdf', 45, 'application/pdf'),
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('data.identity_card', '8123456')
        ->assertJsonPath('data.first_names', 'MARÍA ELENA')
        ->assertJsonPath('data.last_names', 'VARGAS SUÁREZ')
        ->assertJsonPath('data.account.email', '8123456@sigal.local')
        ->assertJsonPath('data.profile_photo.document_type', 'profile_photo')
        ->assertJsonPath('data.open_contract.contract_amount', '5350.50')
        ->assertJsonPath('data.open_contract.office_position.id', $position->id)
        ->assertJsonPath('credentials.email', '8123456@sigal.local');

    $employee = Employee::query()->where('identity_card', '8123456')->firstOrFail();
    $user = $employee->user()->firstOrFail();
    $contract = $employee->openContract()->firstOrFail();

    expect($user->isActive())->toBeTrue()
        ->and($contract->contract_type->value)->toBe('eventual');
    $this->assertDatabaseHas('office_memberships', [
        'employment_contract_id' => $contract->id,
        'user_id' => $user->id,
        'office_id' => $office->id,
    ]);
    $this->assertDatabaseHas('employee_attachments', [
        'employee_id' => $employee->id,
        'document_type' => 'rejap_certificate',
    ]);

    $this->post("/api/human-resources/employees/{$employee->id}/profile-photo", [
        'profile_photo' => UploadedFile::fake()->createWithContent('perfil-actualizado.png', 'foto de perfil actualizada'),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.profile_photo.document_type', 'profile_photo');
    expect($employee->profilePhoto()->firstOrFail()->original_name)->toBe('perfil-actualizado.png');

    $this->patchJson("/api/human-resources/employees/{$employee->id}", [
        'identity_card' => '8123457',
        'first_names' => 'ana sofia',
        'last_names' => 'rios montano',
        'mobile_phone' => '71112223',
        'email' => 'ana.rios@example.test',
        'birth_date' => '1993-06-11',
        'academic_degree' => 'Licenciatura',
        'profession' => 'Ingeniera de Sistemas',
    ])
        ->assertOk()
        ->assertJsonPath('data.identity_card', '8123457')
        ->assertJsonPath('data.first_names', 'ANA SOFIA')
        ->assertJsonPath('data.last_names', 'RIOS MONTANO');
    expect($employee->fresh()->user()->firstOrFail()->name)->toBe('ANA SOFIA RIOS MONTANO');

    $this->post("/api/human-resources/employees/{$employee->id}/attachments", [
        'document_type' => 'other_supporting_document',
        'attachment' => UploadedFile::fake()->create('memorandum.pdf', 20, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.attachments.0.document_type', 'other_supporting_document');
    $this->assertDatabaseHas('employee_attachments', [
        'employee_id' => $employee->id,
        'document_type' => 'other_supporting_document',
    ]);

    $this->postJson('/api/human-resources/employees', humanResourcesPayload($position, ['identity_card' => '8123457']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('identity_card');
});

test('every account created from human resources starts as a simple user even for a superadministrator', function () {
    $administrator = humanResourcesAdministrator();
    Sanctum::actingAs($administrator);
    $office = Office::query()->where('code', 'RRHH')->firstOrFail();
    $position = OfficePosition::query()->create([
        'office_id' => $office->id,
        'name' => 'Profesional de control de accesos',
        'membership_role' => 'official',
        'created_by' => $administrator->id,
    ]);

    $this->getJson('/api/human-resources/bootstrap')
        ->assertOk()
        ->assertJsonCount(1, 'data.roles')
        ->assertJsonPath('data.roles.0.code', RoleCode::SimpleUser->value);

    $this->postJson('/api/human-resources/employees', humanResourcesPayload($position, [
        'identity_card' => '8123499',
        'role' => RoleCode::Observer->value,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors('role');

    expect(Employee::query()->where('identity_card', '8123499')->exists())->toBeFalse();
});

test('finishing a contract retains the person and reuses the inactive account on a later contract', function () {
    $administrator = humanResourcesAdministrator();
    Sanctum::actingAs($administrator);
    $office = Office::query()->create(['code' => 'SIS-TEST', 'name' => 'Sistemas de prueba']);
    $firstPosition = OfficePosition::query()->create([
        'office_id' => $office->id,
        'name' => 'Técnico de soporte',
        'membership_role' => 'official',
        'created_by' => $administrator->id,
    ]);
    $secondPosition = OfficePosition::query()->create([
        'office_id' => $office->id,
        'name' => 'Jefe de sección de soporte',
        'membership_role' => 'manager',
        'created_by' => $administrator->id,
    ]);

    $this->postJson('/api/human-resources/employees', humanResourcesPayload($firstPosition))
        ->assertCreated();
    $employee = Employee::query()->where('identity_card', '8123456')->firstOrFail();
    $originalUser = $employee->user()->firstOrFail();
    $firstContract = $employee->openContract()->firstOrFail();

    $this->postJson("/api/human-resources/contracts/{$firstContract->id}/finish")
        ->assertOk()
        ->assertJsonPath('data.state', 'finished');

    expect($originalUser->fresh()->isActive())->toBeFalse();
    expect(OfficeMembership::query()->where('employment_contract_id', $firstContract->id)->value('effective_to'))->not->toBeNull();

    $this->postJson('/api/human-resources/employees', humanResourcesPayload($secondPosition, [
        'contract_type' => 'tgn',
        'starts_on' => today()->addDay()->toDateString(),
    ]))->assertCreated()
        ->assertJsonPath('credentials', null);

    $employee->refresh();
    expect(Employee::query()->where('identity_card', '8123456')->count())->toBe(1)
        ->and($employee->user()->firstOrFail()->id)->toBe($originalUser->id)
        ->and($employee->user()->firstOrFail()->isActive())->toBeFalse();
});

test('correcting a position as office manager updates the active office membership', function () {
    $administrator = humanResourcesAdministrator();
    Sanctum::actingAs($administrator);
    $office = Office::query()->create(['code' => 'SIS-CORR', 'name' => 'Sistemas de corrección']);
    $position = OfficePosition::query()->create([
        'office_id' => $office->id,
        'name' => 'Jefe de Sistemas',
        'membership_role' => 'official',
        'created_by' => $administrator->id,
    ]);

    $this->postJson('/api/human-resources/employees', humanResourcesPayload($position))
        ->assertCreated();
    $contract = EmploymentContract::query()->firstOrFail();

    $this->patchJson("/api/human-resources/positions/{$position->id}", [
        'name' => 'Jefe de Sección de Sistemas',
        'membership_role' => 'manager',
    ])->assertOk()
        ->assertJsonPath('data.membership_role', 'manager');

    expect($position->fresh()->membership_role->value)->toBe('manager')
        ->and(OfficeMembership::query()->where('employment_contract_id', $contract->id)->firstOrFail()->membership_role->value)->toBe('manager')
        ->and(OfficeMembership::query()->where('employment_contract_id', $contract->id)->value('position_title'))->toBe('Jefe de Sección de Sistemas');
    $this->assertDatabaseHas('activity_logs', ['event' => 'human_resources.office_position.updated']);
});

test('the independent human resources account can access its module but not generic user administration', function () {
    $this->artisan('sigal:bootstrap-human-resources-administrator')->assertExitCode(0);
    $humanResourcesUser = User::query()->where('email', 'rrhh@sigal.local')->firstOrFail();

    expect($humanResourcesUser->employee_id)->toBeNull()
        ->and($humanResourcesUser->isHumanResourcesManager())->toBeTrue()
        ->and($humanResourcesUser->must_change_password)->toBeTrue();
    $humanResourcesUser->update(['must_change_password' => false]);
    Sanctum::actingAs($humanResourcesUser);

    $this->getJson('/api/human-resources/bootstrap')->assertOk()
        ->assertJsonCount(1, 'data.roles')
        ->assertJsonPath('data.roles.0.code', RoleCode::SimpleUser->value);
    $this->getJson('/api/users')->assertForbidden();
});

test('the scheduled expiration closes the contract and disables the account', function () {
    $administrator = humanResourcesAdministrator();
    Sanctum::actingAs($administrator);
    $office = Office::query()->create(['code' => 'ARCH-TEST', 'name' => 'Archivo de prueba']);
    $position = OfficePosition::query()->create([
        'office_id' => $office->id,
        'name' => 'Responsable de archivo',
        'membership_role' => 'official',
        'created_by' => $administrator->id,
    ]);
    $this->postJson('/api/human-resources/employees', humanResourcesPayload($position, [
        'starts_on' => today()->subDays(10)->toDateString(),
        'ends_on' => today()->subDay()->toDateString(),
    ]))->assertCreated();
    $contract = EmploymentContract::query()->firstOrFail();
    $user = $contract->employee->user()->firstOrFail();

    $this->artisan('human-resources:expire-contracts')->assertExitCode(0);

    expect($contract->fresh()->ended_at)->not->toBeNull()
        ->and($user->fresh()->isActive())->toBeFalse();
});

test('a superadministrator can perform the preoperational reset while preserving offices and positions', function () {
    $administrator = humanResourcesAdministrator();
    Sanctum::actingAs($administrator);
    $office = Office::query()->create(['code' => 'RESET-TEST', 'name' => 'Oficina que se conserva']);
    $position = OfficePosition::query()->create([
        'office_id' => $office->id,
        'name' => 'Cargo que se conserva',
        'membership_role' => 'official',
        'created_by' => $administrator->id,
    ]);

    $this->post('/api/human-resources/employees', [
        ...humanResourcesPayload($position),
        'rejap_certificate' => UploadedFile::fake()->create('reset-rejap.pdf', 20, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();
    $employee = Employee::query()->firstOrFail();
    $attachmentPath = $employee->attachments()->firstOrFail()->path;
    DB::table('legislatures')->insert([
        'start_year' => 2026,
        'end_year' => 2027,
        'status' => 'inactive',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/api/administration/operational-reset', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('confirmation');

    $this->postJson('/api/administration/operational-reset', ['confirmation' => 'REINICIAR SIGAL'])
        ->assertOk()
        ->assertJsonPath('data.employees', 1)
        ->assertJsonPath('data.legislatures', 1);

    expect(Employee::query()->count())->toBe(0)
        ->and(EmploymentContract::query()->count())->toBe(0);
    $this->assertDatabaseHas('offices', ['id' => $office->id]);
    $this->assertDatabaseHas('office_positions', ['id' => $position->id]);
    Storage::disk('local')->assertMissing($attachmentPath);
    $this->assertDatabaseHas('activity_logs', ['event' => 'administration.operational_reset.performed']);
});

test('a human resources administrator can import employees from the Excel template format atomically', function () {
    $administrator = humanResourcesAdministrator();
    Sanctum::actingAs($administrator);
    $office = Office::query()->create(['code' => 'IMP', 'name' => 'Oficina de Importación']);
    $position = OfficePosition::query()->create([
        'office_id' => $office->id,
        'name' => 'Técnico de Importación',
        'membership_role' => 'official',
        'created_by' => $administrator->id,
    ]);
    $headers = [
        'carnet_de_identidad', 'nombres', 'apellidos', 'celular', 'correo_electronico', 'direccion', 'numero_cua',
        'fecha_nacimiento', 'libreta_servicio_militar', 'grado_academico', 'profesion', 'tipo_sangre',
        'contacto_emergencia', 'tipo_contrato', 'monto_contrato', 'fecha_inicio_contrato', 'fecha_fin_contrato', 'codigo_oficina',
        'cargo', 'rol',
    ];
    $templateHeaders = array_slice($headers, 0, 19);

    $response = $this->post('/api/human-resources/employees/import', [
        'file' => employeeImportWorkbook([
            $templateHeaders,
            ['9000001', 'María Elena', 'Vargas Suárez', '70000001', null, null, null, '1990-06-12', null, 'Licenciatura', 'Ingeniera de Sistemas', null, null, 'Eventual', '5200.00', today()->toDateString(), null, 'Oficina de Importación', 'Técnico de Importación'],
        ]),
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('data.imported_count', 1)
        ->assertJsonPath('data.credentials.0.email', '9000001@sigal.local');
    $this->assertDatabaseHas('employees', ['identity_card' => '9000001', 'first_names' => 'MARÍA ELENA']);
    $this->assertDatabaseHas('employment_contracts', ['office_position_id' => $position->id]);
    $this->assertDatabaseHas('activity_logs', ['event' => 'human_resources.employee_import.completed']);

    $this->post('/api/human-resources/employees/import', [
        'file' => employeeImportWorkbook([
            $headers,
            ['9000002', 'Ana', 'Rios', '70000002', null, null, null, '1991-06-12', null, 'Licenciatura', 'Abogada', null, null, 'Eventual', null, today()->toDateString(), null, 'IMP', 'Técnico de Importación', 'Usuario simple'],
            ['9000003', 'Julia', 'Lopez', '70000003', null, null, null, '1991-06-12', null, 'Licenciatura', 'Abogada', null, null, 'Eventual', null, today()->toDateString(), null, 'NO-EXISTE', 'Cargo inexistente', 'Usuario simple'],
        ]),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    $this->assertDatabaseMissing('employees', ['identity_card' => '9000002']);
    $this->assertDatabaseMissing('employees', ['identity_card' => '9000003']);
});

test('an authorized administrator can download the import template and its headers are readable', function () {
    $administrator = humanResourcesAdministrator();
    Sanctum::actingAs($administrator);

    $this->get('/api/human-resources/employee-import-template')
        ->assertOk()
        ->assertDownload('plantilla-importacion-funcionarios-sigal.xlsx');

    $template = resource_path('templates/plantilla-importacion-funcionarios-sigal.xlsx');
    $rows = app(EmployeeImportWorkbookReader::class)->read(new UploadedFile(
        $template,
        'plantilla-importacion-funcionarios-sigal.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    ));

    expect($rows[1][0])->toBe('carnet_de_identidad')
        ->and($rows[1][13])->toBe('tipo_contrato')
        ->and($rows[1][18])->toBe('cargo')
        ->and($rows[1])->toHaveCount(19)
        ->and($rows[1])->not->toContain('rol');
});
