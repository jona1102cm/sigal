<?php

namespace Database\Seeders;

use App\Domain\DocumentManagement\Enums\OfficeCapabilityCode;
use App\Models\Office;
use App\Models\OfficeCapability;
use Illuminate\Database\Seeder;

/** Asigna facultades especiales del flujo a las oficinas institucionales definidas. */
class OfficeCapabilitySeeder extends Seeder
{
    public function run(): void
    {
        $archive = Office::query()->where('code', 'URA')->firstOrFail();
        $omaf = Office::query()->where('code', 'OMAF')->firstOrFail();
        $generalSecretariat = Office::query()->where('code', 'SGEN')->firstOrFail();

        foreach ([
            OfficeCapabilityCode::ArchiveExpedients,
            OfficeCapabilityCode::CloseExpedients,
            OfficeCapabilityCode::VoidExpedients,
        ] as $capability) {
            OfficeCapability::query()->firstOrCreate([
                'office_id' => $archive->id,
                'capability' => $capability->value,
            ]);
        }

        OfficeCapability::query()->firstOrCreate([
            'office_id' => $omaf->id,
            'capability' => OfficeCapabilityCode::ApproveReopenings->value,
        ]);

        foreach ([$archive, $generalSecretariat] as $externalReceptionOffice) {
            OfficeCapability::query()->firstOrCreate([
                'office_id' => $externalReceptionOffice->id,
                'capability' => OfficeCapabilityCode::ReceiveExternalDocuments->value,
            ]);
        }
    }
}
