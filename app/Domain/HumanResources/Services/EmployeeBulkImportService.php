<?php

namespace App\Domain\HumanResources\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\HumanResources\DTOs\EmployeeBulkImportResult;
use App\Domain\HumanResources\DTOs\RegisterEmployeeContractData;
use App\Domain\HumanResources\Enums\ContractType;
use App\Models\Office;
use App\Models\OfficePosition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public function __construct(
        private readonly EmployeeImportWorkbookReader $workbookReader,
        private readonly HumanResourcesService $humanResourcesService,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function import(UploadedFile $file, User $actor, RequestAuditContext $context): EmployeeBulkImportResult
    {
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

        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber === $headerRow) {
                continue;
            }

            $record = $this->recordFromRow($row, $columns);

            if ($this->isEmptyRecord($record)) {
                continue;
            }

            $data = $this->normalise($record);
            $validator = Validator::make($data, $this->rules());

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
            $position = $this->positionFor($data['office_code'], $data['position_name']);

            if ($position instanceof ValidationException) {
                $errors[] = $this->rowError($rowNumber, implode(' ', $position->errors()['office_position'] ?? $position->errors()['office_code'] ?? ['Oficina o cargo inválido.']));
                continue;
            }

            $records[] = [
                'row' => $rowNumber,
                'data' => $data,
                'office_position_id' => $position->id,
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

        return $this->persist($records, $actor, $context);
    }

    /**
     * @param array<int, array{row: int, data: array<string, string|null>, office_position_id: int}> $records
     */
    private function persist(array $records, User $actor, RequestAuditContext $context): EmployeeBulkImportResult
    {
        $credentials = [];
        $errors = [];
        DB::beginTransaction();

        try {
            foreach ($records as $record) {
                try {
                    $result = $this->humanResourcesService->registerEmployeeAndContract(
                        $this->registrationData($record['data'], $record['office_position_id']),
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
     * @param array<int, array<int, string|null>> $rows
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
     * @param array<int, string|null> $row
     * @param array<string, int> $columns
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
            'office_code' => ['required', 'string', 'max:50'],
            'position_name' => ['required', 'string', 'max:255'],
            'role' => ['nullable', Rule::enum(RoleCode::class)],
        ];
    }

    private function positionFor(string $officeCode, string $positionName): OfficePosition|ValidationException
    {
        $office = Office::query()
            ->where(function ($query) use ($officeCode): void {
                $query->whereRaw('lower(code) = ?', [mb_strtolower($officeCode)])
                    ->orWhereRaw('lower(name) = ?', [mb_strtolower($officeCode)]);
            })
            ->first();

        if ($office === null) {
            return ValidationException::withMessages([
                'office_code' => "No existe la oficina con código o nombre {$officeCode}.",
            ]);
        }

        $positions = OfficePosition::query()
            ->where('office_id', $office->id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($positionName)])
            ->get();

        if ($positions->count() !== 1) {
            return ValidationException::withMessages([
                'office_position' => "No se encontró un cargo único llamado {$positionName} en la oficina {$office->code}.",
            ]);
        }

        return $positions->first();
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
