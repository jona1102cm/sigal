<?php

namespace App\Domain\HumanResources\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\HumanResources\DTOs\CreateOfficePositionData;
use App\Domain\HumanResources\DTOs\EmployeeBulkImportResult;
use App\Domain\HumanResources\DTOs\RegisterEmployeeContractData;
use App\Domain\HumanResources\DTOs\UpdateOfficePositionData;
use App\Domain\HumanResources\Enums\ContractType;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\Office;
use App\Models\OfficePosition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Valida y procesa la carga masiva de funcionarios desde la plantilla oficial.
 *
 * Resuelve etiquetas españolas contra catálogos reales y reutiliza el alta manual,
 * evitando que Excel tenga un segundo conjunto de reglas de contratación.
 */
class EmployeeBulkImportService
{
    /** @var array<int, string> */
    private const REQUIRED_COLUMNS = [
        'identity_card',
        'first_names',
        'last_names',
        'mobile_phone',
        'birth_date',
        'academic_degree',
        'profession',
        'contract_type',
        'starts_on',
        'office_code',
        'position_name',
    ];

    /** @var array<int, string> */
    private const SUPPORTED_COLUMNS = [
        ...self::REQUIRED_COLUMNS,
        'email',
        'address',
        'cua_number',
        'military_service_booklet',
        'blood_type',
        'emergency_contact',
        'contract_amount',
        'ends_on',
        'role',
    ];

    /** @var array<string, string> */
    private const COLUMN_ALIASES = [
        'identity_card' => 'identity_card',
        'carnet_de_identidad' => 'identity_card',
        'carnet_identidad' => 'identity_card',
        'first_names' => 'first_names',
        'nombres' => 'first_names',
        'last_names' => 'last_names',
        'apellidos' => 'last_names',
        'mobile_phone' => 'mobile_phone',
        'celular' => 'mobile_phone',
        'email' => 'email',
        'correo_electronico' => 'email',
        'address' => 'address',
        'direccion' => 'address',
        'cua_number' => 'cua_number',
        'numero_cua' => 'cua_number',
        'birth_date' => 'birth_date',
        'fecha_nacimiento' => 'birth_date',
        'military_service_booklet' => 'military_service_booklet',
        'libreta_servicio_militar' => 'military_service_booklet',
        'academic_degree' => 'academic_degree',
        'grado_academico' => 'academic_degree',
        'profession' => 'profession',
        'profesion' => 'profession',
        'blood_type' => 'blood_type',
        'tipo_sangre' => 'blood_type',
        'emergency_contact' => 'emergency_contact',
        'contacto_emergencia' => 'emergency_contact',
        'contract_type' => 'contract_type',
        'tipo_contrato' => 'contract_type',
        'contract_amount' => 'contract_amount',
        'monto_contrato' => 'contract_amount',
        'starts_on' => 'starts_on',
        'fecha_inicio_contrato' => 'starts_on',
        'ends_on' => 'ends_on',
        'fecha_fin_contrato' => 'ends_on',
        'office_code' => 'office_code',
        'codigo_oficina' => 'office_code',
        'position_name' => 'position_name',
        'cargo' => 'position_name',
        'role' => 'role',
        'rol' => 'role',
    ];

    /** @var array<string, string> */
    private const ATTRIBUTE_NAMES = [
        'identity_card' => 'carnet de identidad',
        'first_names' => 'nombres',
        'last_names' => 'apellidos',
        'mobile_phone' => 'celular',
        'email' => 'correo electrónico',
        'address' => 'dirección',
        'cua_number' => 'número CUA',
        'birth_date' => 'fecha de nacimiento',
        'military_service_booklet' => 'libreta de servicio militar',
        'academic_degree' => 'grado académico',
        'profession' => 'profesión',
        'blood_type' => 'tipo de sangre',
        'emergency_contact' => 'contacto de emergencia',
        'contract_type' => 'tipo de contrato',
        'contract_amount' => 'monto del contrato',
        'starts_on' => 'fecha de inicio del contrato',
        'ends_on' => 'fecha de fin del contrato',
        'office_code' => 'oficina',
        'position_name' => 'cargo',
        'role' => 'rol',
    ];

    public function __construct(
        private readonly EmployeeImportWorkbookReader $workbookReader,
        private readonly HumanResourcesService $humanResourcesService,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array<int, array{key: string, membership_role: string}>  $positionResolutions
     */
    public function import(
        UploadedFile $file,
        User $actor,
        RequestAuditContext $context,
        array $positionResolutions = [],
    ): EmployeeBulkImportResult {
        $rows = $this->workbookReader->read($file);
        [$headerRow, $columns] = $this->columns($rows);
        $missingColumns = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($columns)));

        if ($missingColumns !== []) {
            throw ValidationException::withMessages([
                'file' => 'Faltan las columnas obligatorias: '.implode(', ', $missingColumns).'. Descargue nuevamente la plantilla.',
            ]);
        }

        $records = [];
        $errors = [];
        $seenIdentityCards = [];
        $missingPositions = [];
        $officeCatalog = Office::query()
            ->active()
            ->supportingStaffing()
            ->with(['positions' => fn ($query) => $query->withExists('contracts')])
            ->get();

        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber === $headerRow) {
                continue;
            }

            $record = $this->recordFromRow($row, $columns);

            if ($this->isEmptyRecord($record)) {
                continue;
            }

            $data = $this->normalise($record);
            $validator = Validator::make(
                $data,
                $this->rules(),
                $this->validationMessages(),
                self::ATTRIBUTE_NAMES,
            );

            if ($validator->fails()) {
                $errors[] = $this->rowError($rowNumber, implode(' ', $validator->errors()->all()));

                continue;
            }

            $identityCardKey = mb_strtolower($data['identity_card']);

            if (isset($seenIdentityCards[$identityCardKey])) {
                $errors[] = $this->rowError($rowNumber, "El CI ya aparece en la fila {$seenIdentityCards[$identityCardKey]}.");

                continue;
            }

            $seenIdentityCards[$identityCardKey] = $rowNumber;
            $positionSelection = $this->positionFor(
                $data['office_code'],
                $data['position_name'],
                $officeCatalog,
            );

            if ($positionSelection instanceof ValidationException) {
                $errors[] = $this->rowError($rowNumber, implode(' ', $positionSelection->errors()['office_position'] ?? $positionSelection->errors()['office_code'] ?? ['Oficina o cargo inválido.']));

                continue;
            }

            $position = $positionSelection['position'];
            $missingPositionKey = null;

            if ($position === null) {
                $missingPositionKey = $this->missingPositionKey(
                    $positionSelection['office']->id,
                    $data['position_name'],
                );

                if (! isset($missingPositions[$missingPositionKey])) {
                    $missingPositions[$missingPositionKey] = $this->missingPositionDefinition(
                        $missingPositionKey,
                        $positionSelection['office'],
                        $data['position_name'],
                    );
                }

                $missingPositions[$missingPositionKey]['rows'][] = $rowNumber;
            }

            $records[] = [
                'row' => $rowNumber,
                'data' => $data,
                'office_position_id' => $position?->id,
                'missing_position_key' => $missingPositionKey,
            ];
        }

        if ($records === [] && $errors === []) {
            throw ValidationException::withMessages([
                'file' => 'La hoja no contiene filas de funcionarios para importar.',
            ]);
        }

        if ($errors !== []) {
            $this->recordRejectedImport($actor, $context, count($records) + count($errors), $errors);

            return new EmployeeBulkImportResult(0, [], $errors);
        }

        if ($missingPositions !== [] && $positionResolutions === []) {
            return new EmployeeBulkImportResult(
                importedCount: 0,
                credentials: [],
                missingPositions: array_values($missingPositions),
            );
        }

        [$resolutionMap, $resolutionErrors] = $this->validatedPositionResolutions(
            $missingPositions,
            $positionResolutions,
        );

        if ($resolutionErrors !== []) {
            $this->recordRejectedImport($actor, $context, count($records), $resolutionErrors);

            return new EmployeeBulkImportResult(0, [], $resolutionErrors);
        }

        return $this->persist($records, $missingPositions, $resolutionMap, $actor, $context);
    }

    /**
     * @param  array<int, array{row: int, data: array<string, string|null>, office_position_id: int|null, missing_position_key: string|null}>  $records
     * @param  array<string, array{key: string, office_id: int, office_code: string, office_name: string, position_name: string, rows: array<int, int>, office_requires_manager: bool, existing_manager_position: array{id: int, name: string, has_contracts: bool}|null}>  $missingPositions
     * @param  array<string, OfficeMembershipRole>  $resolutionMap
     */
    private function persist(
        array $records,
        array $missingPositions,
        array $resolutionMap,
        User $actor,
        RequestAuditContext $context,
    ): EmployeeBulkImportResult {
        $credentials = [];
        $errors = [];
        DB::beginTransaction();

        try {
            $createdPositionIds = $this->materialiseMissingPositions(
                $missingPositions,
                $resolutionMap,
                $actor,
                $context,
            );

            foreach ($records as $record) {
                try {
                    $officePositionId = $record['office_position_id']
                        ?? $createdPositionIds[$record['missing_position_key']];
                    $result = $this->humanResourcesService->registerEmployeeAndContract(
                        $this->registrationData($record['data'], $officePositionId),
                        [],
                        $actor,
                        $context,
                    );

                    if ($result->temporaryPassword !== null) {
                        $credentials[] = [
                            'row' => $record['row'],
                            'name' => $result->employee->fullName(),
                            'email' => $result->user->email,
                            'temporary_password' => $result->temporaryPassword,
                        ];
                    }
                } catch (ValidationException $exception) {
                    $errors[] = $this->rowError($record['row'], implode(' ', $exception->errors()['identity_card'] ?? $exception->errors()['email'] ?? $exception->errors()['office_position_id'] ?? $exception->errors()['role'] ?? ['No fue posible registrar esta fila.']));
                }
            }

            if ($errors !== []) {
                DB::rollBack();
                $this->recordRejectedImport($actor, $context, count($records), $errors);

                return new EmployeeBulkImportResult(0, [], $errors);
            }

            DB::commit();
        } catch (ValidationException $exception) {
            DB::rollBack();
            $errors = $exception->errors()['office_position']
                ?? $exception->errors()['membership_role']
                ?? $exception->errors()['office_id']
                ?? ['No fue posible crear los cargos indicados.'];
            $this->recordRejectedImport($actor, $context, count($records), $errors);

            return new EmployeeBulkImportResult(0, [], $errors);
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $this->activityLogger->record(
            event: 'human_resources.employee_import.completed',
            actor: $actor,
            subject: null,
            context: $context,
            newValues: [
                'imported_count' => count($records),
                'created_account_count' => count($credentials),
            ],
        );

        return new EmployeeBulkImportResult(count($records), $credentials);
    }

    /**
     * @param  array<int, array<int, string|null>>  $rows
     * @return array{0:int, 1:array<string, int>}
     */
    private function columns(array $rows): array
    {
        $headerRow = array_key_first($rows);

        if ($headerRow === null) {
            throw ValidationException::withMessages([
                'file' => 'La hoja de cálculo está vacía.',
            ]);
        }

        $columns = [];

        foreach ($rows[$headerRow] as $columnIndex => $header) {
            $name = self::COLUMN_ALIASES[$this->headerKey((string) $header)] ?? null;

            if ($name !== null) {
                if (array_key_exists($name, $columns)) {
                    throw ValidationException::withMessages([
                        'file' => "La columna {$name} aparece mas de una vez. Descargue nuevamente la plantilla.",
                    ]);
                }

                $columns[$name] = $columnIndex;
            }
        }

        return [$headerRow, $columns];
    }

    /**
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $columns
     * @return array<string, string|null>
     */
    private function recordFromRow(array $row, array $columns): array
    {
        $record = [];

        foreach (self::SUPPORTED_COLUMNS as $column) {
            $record[$column] = isset($columns[$column]) ? ($row[$columns[$column]] ?? null) : null;
        }

        return $record;
    }

    /** @param array<string, string|null> $record */
    private function isEmptyRecord(array $record): bool
    {
        return collect($record)->filter(fn (?string $value): bool => $value !== null && trim($value) !== '')->isEmpty();
    }

    /** @param array<string, string|null> $record
     * @return array<string, string|null>
     */
    private function normalise(array $record): array
    {
        foreach ($record as $column => $value) {
            $record[$column] = $value === null || trim($value) === '' ? null : trim($value);
        }

        $record['contract_type'] = $this->contractTypeCode($record['contract_type']);
        $record['role'] = $this->roleCode($record['role']);

        if ($record['office_code'] !== null) {
            $record['office_code'] = mb_strtolower($record['office_code']);
        }

        foreach (['birth_date', 'starts_on', 'ends_on'] as $column) {
            $record[$column] = $this->normaliseDate($record[$column]);
        }

        return $record;
    }

    private function headerKey(string $header): string
    {
        return mb_strtolower(trim(Str::ascii($header)));
    }

    private function contractTypeCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this->comparisonKey($value)) {
            'eventual' => ContractType::Eventual->value,
            'consultoria de linea', 'line consultancy' => ContractType::LineConsultancy->value,
            'tgn' => ContractType::Tgn->value,
            'funcionamiento' => ContractType::Functioning->value,
            default => mb_strtolower(trim($value)),
        };
    }

    private function roleCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this->comparisonKey($value)) {
            'usuario simple', 'simple user' => RoleCode::SimpleUser->value,
            'observador', 'observer' => RoleCode::Observer->value,
            'administrador de recursos humanos', 'administrador de rr hh', 'human resources manager' => RoleCode::HumanResourcesManager->value,
            'superadministrador', 'super administrator' => RoleCode::SuperAdministrator->value,
            default => mb_strtolower(trim($value)),
        };
    }

    private function comparisonKey(string $value): string
    {
        $key = Str::ascii(mb_strtolower(trim($value)));
        $key = preg_replace('/[._-]+/', ' ', $key) ?? $key;

        return preg_replace('/\s+/', ' ', $key) ?? $key;
    }

    private function normaliseDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) floor((float) $value))->toDateString();
        }

        return $value;
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'identity_card' => ['required', 'string', 'max:30'],
            'first_names' => ['required', 'string', 'max:255'],
            'last_names' => ['required', 'string', 'max:255'],
            'mobile_phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'string', 'email:filter', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'cua_number' => ['nullable', 'string', 'max:50'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today'],
            'military_service_booklet' => ['nullable', 'string', 'max:100'],
            'academic_degree' => ['required', 'string', 'max:255'],
            'profession' => ['required', 'string', 'max:255'],
            'blood_type' => ['nullable', 'string', 'max:20'],
            'emergency_contact' => ['nullable', 'string', 'max:2000'],
            'contract_type' => ['required', Rule::enum(ContractType::class)],
            'contract_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'office_code' => ['required', 'string', 'max:255'],
            'position_name' => ['required', 'string', 'max:255'],
            'role' => ['nullable', Rule::enum(RoleCode::class)],
        ];
    }

    /** @return array<string, string> */
    private function validationMessages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe contener texto.',
            'max.string' => 'El campo :attribute no debe superar :max caracteres.',
            'email' => 'El campo :attribute debe contener un correo electrónico válido.',
            'date_format' => 'El campo :attribute debe tener el formato AAAA-MM-DD.',
            'before' => 'El campo :attribute debe ser una fecha anterior a hoy.',
            'enum' => 'El valor seleccionado en :attribute no es válido.',
            'numeric' => 'El campo :attribute debe ser un número.',
            'min.numeric' => 'El campo :attribute debe ser mayor o igual a :min.',
            'decimal' => 'El campo :attribute debe tener entre :min y :max decimales.',
            'after_or_equal' => 'El campo :attribute debe ser una fecha posterior o igual a :date.',
        ];
    }

    /**
     * @param  EloquentCollection<int, Office>  $officeCatalog
     * @return array{office: Office, position: OfficePosition|null}|ValidationException
     */
    private function positionFor(
        string $officeCode,
        string $positionName,
        EloquentCollection $officeCatalog,
    ): array|ValidationException {
        $officeKey = $this->comparisonKey($officeCode);
        $offices = $officeCatalog
            ->filter(fn (Office $office): bool => in_array($officeKey, [
                $this->comparisonKey($office->code),
                $this->comparisonKey($office->name),
            ], true))
            ->values();

        if ($offices->count() !== 1) {
            return ValidationException::withMessages([
                'office_code' => "No se encontró una oficina activa y única con código o nombre {$officeCode}.",
            ]);
        }

        $office = $offices->first();
        $positionKey = $this->comparisonKey($positionName);
        $positions = $office->positions
            ->filter(fn (OfficePosition $position): bool => $this->comparisonKey($position->name) === $positionKey)
            ->values();

        if ($positions->count() > 1) {
            return ValidationException::withMessages([
                'office_position' => "Existe más de un cargo equivalente a {$positionName} en la oficina {$office->code}. Corrija el catálogo antes de importar.",
            ]);
        }

        return [
            'office' => $office,
            'position' => $positions->first(),
        ];
    }

    private function missingPositionKey(int $officeId, string $positionName): string
    {
        return hash('sha256', $officeId."\0".$this->comparisonKey($positionName));
    }

    /**
     * @return array{key: string, office_id: int, office_code: string, office_name: string, position_name: string, rows: array<int, int>, office_requires_manager: bool, existing_manager_position: array{id: int, name: string, has_contracts: bool}|null}
     */
    private function missingPositionDefinition(string $key, Office $office, string $positionName): array
    {
        $managerPositions = $office->positions
            ->filter(fn (OfficePosition $position): bool => $position->membership_role === OfficeMembershipRole::Manager)
            ->values();
        $managerPosition = $managerPositions->count() === 1 ? $managerPositions->first() : null;

        return [
            'key' => $key,
            'office_id' => $office->id,
            'office_code' => $office->code,
            'office_name' => $office->name,
            'position_name' => trim($positionName),
            'rows' => [],
            'office_requires_manager' => $office->requires_manager && $managerPositions->count() <= 1,
            'existing_manager_position' => $managerPosition === null ? null : [
                'id' => $managerPosition->id,
                'name' => $managerPosition->name,
                'has_contracts' => (bool) $managerPosition->contracts_exists,
            ],
        ];
    }

    /**
     * @param  array<string, array{key: string, office_id: int, office_code: string, office_name: string, position_name: string, rows: array<int, int>, office_requires_manager: bool, existing_manager_position: array{id: int, name: string, has_contracts: bool}|null}>  $missingPositions
     * @param  array<int, array{key: string, membership_role: string}>  $positionResolutions
     * @return array{0: array<string, OfficeMembershipRole>, 1: array<int, string>}
     */
    private function validatedPositionResolutions(array $missingPositions, array $positionResolutions): array
    {
        $resolutionMap = [];

        foreach ($positionResolutions as $resolution) {
            $resolutionMap[$resolution['key']] = OfficeMembershipRole::from($resolution['membership_role']);
        }

        $expectedKeys = array_keys($missingPositions);
        $receivedKeys = array_keys($resolutionMap);
        sort($expectedKeys);
        sort($receivedKeys);

        if ($expectedKeys !== $receivedKeys) {
            return [[], [
                'La clasificación enviada no corresponde a los cargos que contiene el archivo. Vuelva a validar la planilla antes de confirmar.',
            ]];
        }

        $errors = [];
        $managerKeysByOffice = [];

        foreach ($resolutionMap as $key => $membershipRole) {
            $definition = $missingPositions[$key];

            if ($membershipRole !== OfficeMembershipRole::Manager) {
                continue;
            }

            if (! $definition['office_requires_manager']) {
                $errors[] = "La oficina {$definition['office_name']} no admite crear otro cargo responsable desde esta importación.";

                continue;
            }

            if (($definition['existing_manager_position']['has_contracts'] ?? false) === true) {
                $currentName = $definition['existing_manager_position']['name'];
                $errors[] = "La oficina {$definition['office_name']} ya tiene el cargo responsable {$currentName} con historial. Actualice primero ese cargo desde Recursos Humanos.";

                continue;
            }

            $managerKeysByOffice[$definition['office_id']][] = $key;
        }

        foreach ($managerKeysByOffice as $officeId => $keys) {
            if (count($keys) <= 1) {
                continue;
            }

            $officeName = $missingPositions[$keys[0]]['office_name'];
            $errors[] = "Solo un cargo nuevo puede clasificarse como responsable de {$officeName}.";
        }

        return [$resolutionMap, $errors];
    }

    /**
     * @param  array<string, array{key: string, office_id: int, office_code: string, office_name: string, position_name: string, rows: array<int, int>, office_requires_manager: bool, existing_manager_position: array{id: int, name: string, has_contracts: bool}|null}>  $missingPositions
     * @param  array<string, OfficeMembershipRole>  $resolutionMap
     * @return array<string, int>
     */
    private function materialiseMissingPositions(
        array $missingPositions,
        array $resolutionMap,
        User $actor,
        RequestAuditContext $context,
    ): array {
        $positionIds = [];

        foreach ($missingPositions as $key => $definition) {
            $office = Office::query()->lockForUpdate()->findOrFail($definition['office_id']);
            $membershipRole = $resolutionMap[$key];
            $positionKey = $this->comparisonKey($definition['position_name']);
            $equivalentPositions = OfficePosition::query()
                ->where('office_id', $office->id)
                ->lockForUpdate()
                ->get()
                ->filter(fn (OfficePosition $position): bool => $this->comparisonKey($position->name) === $positionKey)
                ->values();

            if ($equivalentPositions->count() > 1) {
                throw ValidationException::withMessages([
                    'office_position' => "El catálogo de {$office->name} contiene cargos equivalentes a {$definition['position_name']}. Corríjalo antes de importar.",
                ]);
            }

            if ($equivalentPositions->count() === 1) {
                $position = $equivalentPositions->first();

                if ($position->membership_role !== $membershipRole) {
                    throw ValidationException::withMessages([
                        'office_position' => "El cargo {$position->name} fue creado con otra función mientras se validaba el archivo. Vuelva a iniciar la importación.",
                    ]);
                }

                $positionIds[$key] = $position->id;

                continue;
            }

            if ($membershipRole === OfficeMembershipRole::Manager) {
                $managerPositions = OfficePosition::query()
                    ->where('office_id', $office->id)
                    ->where('membership_role', OfficeMembershipRole::Manager->value)
                    ->with('office')
                    ->lockForUpdate()
                    ->get();

                if ($managerPositions->count() > 1) {
                    throw ValidationException::withMessages([
                        'office_position' => "La oficina {$office->name} tiene más de un cargo responsable. Corrija el catálogo antes de importar.",
                    ]);
                }

                if ($managerPositions->count() === 1) {
                    $managerPosition = $managerPositions->first();

                    if ($managerPosition->contracts()->exists()) {
                        throw ValidationException::withMessages([
                            'office_position' => "El cargo responsable {$managerPosition->name} ya tiene historial. Actualícelo primero desde Recursos Humanos.",
                        ]);
                    }

                    $position = $this->humanResourcesService->updateOfficePosition(
                        $managerPosition,
                        new UpdateOfficePositionData($definition['position_name'], $membershipRole),
                        $actor,
                        $context,
                    );
                    $positionIds[$key] = $position->id;

                    continue;
                }
            }

            $position = $this->humanResourcesService->createOfficePosition(
                new CreateOfficePositionData($office->id, $definition['position_name'], $membershipRole),
                $actor,
                $context,
            );
            $positionIds[$key] = $position->id;
        }

        return $positionIds;
    }

    /** @param array<string, string|null> $data */
    private function registrationData(array $data, int $officePositionId): RegisterEmployeeContractData
    {
        return new RegisterEmployeeContractData(
            identityCard: $data['identity_card'],
            firstNames: $data['first_names'],
            lastNames: $data['last_names'],
            mobilePhone: $data['mobile_phone'],
            email: $data['email'],
            address: $data['address'],
            cuaNumber: $data['cua_number'],
            birthDate: CarbonImmutable::parse($data['birth_date']),
            militaryServiceBooklet: $data['military_service_booklet'],
            academicDegree: $data['academic_degree'],
            profession: $data['profession'],
            bloodType: $data['blood_type'],
            emergencyContact: $data['emergency_contact'],
            contractType: ContractType::from($data['contract_type']),
            contractAmount: $data['contract_amount'],
            startsOn: CarbonImmutable::parse($data['starts_on']),
            endsOn: $data['ends_on'] === null ? null : CarbonImmutable::parse($data['ends_on']),
            officePositionId: $officePositionId,
            role: $data['role'] === null ? RoleCode::SimpleUser : RoleCode::from($data['role']),
        );
    }

    /** @param array<int, string> $errors */
    private function recordRejectedImport(User $actor, RequestAuditContext $context, int $rowCount, array $errors): void
    {
        $this->activityLogger->record(
            event: 'human_resources.employee_import.rejected',
            actor: $actor,
            subject: null,
            context: $context,
            newValues: [
                'row_count' => $rowCount,
                'error_count' => count($errors),
                'error_rows' => array_map(fn (string $error): string => strtok($error, ':'), $errors),
            ],
        );
    }

    private function rowError(int $row, string $message): string
    {
        return "Fila {$row}: {$message}";
    }
}
