<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_number_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expedient_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('legislature_id')->constrained()->restrictOnDelete();
            $table->foreignId('issuing_office_id')->constrained('offices')->restrictOnDelete();
            $table->unsignedBigInteger('sequence_number');
            $table->string('formatted_number')->unique();
            $table->unsignedInteger('last_version_number')->default(1);
            $table->timestampsTz();

            $table->unique(['legislature_id', 'issuing_office_id', 'sequence_number']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expedient_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('issuing_office_id')->constrained('offices')->restrictOnDelete();
            $table->foreignId('document_number_series_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supersedes_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->unsignedInteger('version_number')->default(0);
            $table->string('status', 20)->default('draft')->index();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampsTz();

            $table->unique(['document_number_series_id', 'version_number']);
            $table->index(['expedient_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE documents
            ADD CONSTRAINT documents_status_check
            CHECK (status IN ('draft', 'issued', 'superseded'))
        SQL);

        Schema::create('document_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('title');
            $table->longText('content')->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at');
            $table->timestampsTz();

            $table->unique(['document_id', 'revision_number']);
        });

        Schema::create('document_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('storage_disk', 100);
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime_type', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->char('content_hash', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['document_id', 'content_hash']);
        });

        Schema::create('document_expedient_movement', function (Blueprint $table) {
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('expedient_movement_id')->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->primary(['document_id', 'expedient_movement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_expedient_movement');
        Schema::dropIfExists('document_attachments');
        Schema::dropIfExists('document_revisions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_number_series');
    }
};
