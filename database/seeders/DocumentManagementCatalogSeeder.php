<?php

namespace Database\Seeders;

use App\Domain\DocumentManagement\Enums\CatalogStatus;
use App\Models\ConfidentialityLevel;
use App\Models\DocumentType;
use Illuminate\Database\Seeder;

/** Registra los tipos documentales y niveles de confidencialidad iniciales. */
class DocumentManagementCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->documentTypes() as $type) {
            DocumentType::query()->updateOrCreate(['code' => $type['code']], $type + [
                'is_official' => true,
                'status' => CatalogStatus::Active,
            ]);
        }

        foreach ([
            ['code' => 'PUBLIC_INSTITUTIONAL', 'name' => 'Público institucional', 'requires_explicit_access' => false, 'sort_order' => 10],
            ['code' => 'INTERNAL', 'name' => 'Interno', 'requires_explicit_access' => false, 'sort_order' => 20],
            ['code' => 'RESERVED', 'name' => 'Reservado', 'requires_explicit_access' => true, 'sort_order' => 30],
            ['code' => 'CONFIDENTIAL', 'name' => 'Confidencial', 'requires_explicit_access' => true, 'sort_order' => 40],
        ] as $level) {
            ConfidentialityLevel::query()->updateOrCreate(['code' => $level['code']], $level + ['status' => CatalogStatus::Active]);
        }
    }

    /** @return list<array{code: string, name: string}> */
    private function documentTypes(): array
    {
        return [
            ['code' => 'LETTER', 'name' => 'Carta'],
            ['code' => 'OFFICIAL_LETTER', 'name' => 'Oficio'],
            ['code' => 'INTERNAL_NOTE', 'name' => 'Nota Interna'],
            ['code' => 'MEMORANDUM', 'name' => 'Memorándum'],
            ['code' => 'REPORT', 'name' => 'Informe'],
            ['code' => 'TECHNICAL_REPORT', 'name' => 'Informe Técnico'],
            ['code' => 'LEGAL_REPORT', 'name' => 'Informe Legal'],
            ['code' => 'RESOLUTION', 'name' => 'Resolución'],
            ['code' => 'CIRCULAR', 'name' => 'Circular'],
            ['code' => 'REQUEST', 'name' => 'Solicitud'],
            ['code' => 'RESPONSE', 'name' => 'Respuesta'],
            ['code' => 'OPINION', 'name' => 'Dictamen'],
            ['code' => 'BILL_PROJECT', 'name' => 'Proyecto de Ley'],
            ['code' => 'RESOLUTION_PROJECT', 'name' => 'Proyecto de Resolución'],
            ['code' => 'MINUTE', 'name' => 'Minuta'],
            ['code' => 'INFORMATION_REQUEST', 'name' => 'Petición de Informe'],
            ['code' => 'MINUTES', 'name' => 'Acta'],
            ['code' => 'AGREEMENT', 'name' => 'Convenio'],
            ['code' => 'CONTRACT', 'name' => 'Contrato'],
            ['code' => 'QUOTATION', 'name' => 'Cotización'],
            ['code' => 'CERTIFICATION', 'name' => 'Certificación'],
            ['code' => 'ENDORSEMENT', 'name' => 'Proveído'],
        ];
    }
}
