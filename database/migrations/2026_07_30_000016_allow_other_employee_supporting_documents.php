<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE employee_attachments DROP CONSTRAINT employee_attachments_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE employee_attachments
            ADD CONSTRAINT employee_attachments_type_check
            CHECK (document_type IN (
                'rejap_certificate', 'cenvi_certificate', 'electoral_registry_certificate',
                'profile_photo', 'other_supporting_document'
            ))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE employee_attachments DROP CONSTRAINT employee_attachments_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE employee_attachments
            ADD CONSTRAINT employee_attachments_type_check
            CHECK (document_type IN (
                'rejap_certificate', 'cenvi_certificate', 'electoral_registry_certificate', 'profile_photo'
            ))
        SQL);
    }
};
