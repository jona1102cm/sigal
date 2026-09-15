<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_units', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('symbol', 20);
            $table->boolean('allows_fraction')->default(false);
            $table->string('status', 20)->default('active')->index();
            $table->timestampsTz();
        });

        Schema::create('warehouse_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('warehouse_categories')->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });

        Schema::create('warehouse_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('measurement_unit_id')->constrained()->restrictOnDelete();
            $table->string('code', 60)->unique();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->decimal('minimum_stock', 18, 4)->default(0);
            $table->decimal('stock_on_hand', 18, 4)->default(0);
            $table->string('physical_location', 255)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['warehouse_category_id', 'status']);
            $table->index('name');
        });

        Schema::create('material_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expedient_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('request_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('requesting_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requesting_office_id')->constrained('offices')->restrictOnDelete();
            $table->string('status', 30)->default('draft')->index();
            $table->string('current_stage', 30)->default('draft')->index();
            $table->string('fulfillment_outcome', 20)->nullable();
            $table->unsignedInteger('current_revision_number')->default(1);
            $table->text('justification');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('delivery_decided_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index(['requesting_user_id', 'status']);
            $table->index(['requesting_office_id', 'status']);
        });

        Schema::create('material_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('material_request_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->unsignedInteger('sort_order');
            $table->foreignId('warehouse_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('measurement_unit_id')->constrained()->restrictOnDelete();
            $table->string('item_name', 180);
            $table->decimal('requested_quantity', 18, 4);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['material_request_id', 'revision_number', 'sort_order'], 'material_request_items_revision_sort_unique');
            $table->index(['material_request_id', 'revision_number']);
        });

        Schema::create('material_request_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('material_request_id')->constrained()->restrictOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('from_stage', 30)->nullable();
            $table->string('to_stage', 30);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('office_id')->nullable()->constrained('offices')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('decided_at');
            $table->timestampsTz();

            $table->index(['material_request_id', 'decided_at']);
        });

        Schema::create('warehouse_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_number', 80)->unique();
            $table->string('supplier_name', 180);
            $table->string('supplier_tax_id', 40)->nullable();
            $table->string('reference_type', 20);
            $table->string('reference_number', 100);
            $table->date('reference_date');
            $table->date('received_on');
            $table->char('currency', 3)->default('BOB');
            $table->decimal('total_amount', 18, 2);
            $table->text('observations')->nullable();
            $table->foreignId('registered_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('warehouse_responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at');
            $table->timestampsTz();

            $table->index(['received_on', 'supplier_name']);
        });

        Schema::create('warehouse_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('subtotal', 18, 2);
            $table->string('lot_number', 100)->nullable();
            $table->date('expires_on')->nullable();
            $table->string('physical_location', 255)->nullable();
            $table->timestampsTz();
        });

        Schema::create('warehouse_receipt_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_receipt_id')->constrained()->restrictOnDelete();
            $table->string('storage_disk', 100);
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime_type', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->char('content_hash', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['warehouse_receipt_id', 'content_hash'], 'warehouse_receipt_attachments_hash_unique');
        });

        Schema::create('warehouse_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('material_request_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('act_document_id')->nullable()->unique()->constrained('documents')->restrictOnDelete();
            $table->string('delivery_number', 80)->unique();
            $table->foreignId('delivered_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('warehouse_responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_receiver_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('receiver_authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('receiver_authorization_reason')->nullable();
            $table->timestampTz('receiver_authorized_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('confirmation_observations')->nullable();
            $table->string('act_verification_code', 64)->nullable()->unique();
            $table->char('act_hash', 64)->nullable();
            $table->timestampTz('delivered_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('warehouse_delivery_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_request_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_item_id')->constrained()->restrictOnDelete();
            $table->decimal('requested_quantity_snapshot', 18, 4);
            $table->decimal('delivered_quantity', 18, 4);
            $table->text('over_delivery_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['warehouse_delivery_id', 'material_request_item_id'], 'warehouse_delivery_request_item_unique');
        });

        Schema::create('warehouse_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_item_id')->constrained()->restrictOnDelete();
            $table->string('movement_type', 20);
            $table->decimal('quantity_delta', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->foreignId('warehouse_receipt_line_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_delivery_line_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['warehouse_item_id', 'occurred_at']);
        });

        Schema::create('warehouse_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('sequence_type', 30);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampsTz();

            $table->unique(['sequence_type', 'year']);
        });

        DB::statement("ALTER TABLE measurement_units ADD CONSTRAINT measurement_units_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement("ALTER TABLE warehouse_categories ADD CONSTRAINT warehouse_categories_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement("ALTER TABLE warehouse_items ADD CONSTRAINT warehouse_items_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement('ALTER TABLE warehouse_items ADD CONSTRAINT warehouse_items_stock_check CHECK (minimum_stock >= 0 AND stock_on_hand >= 0)');
        DB::statement("ALTER TABLE material_requests ADD CONSTRAINT material_requests_status_check CHECK (status IN ('draft', 'pending', 'observed', 'in_attention', 'pending_receipt', 'received', 'not_attended', 'rejected', 'closed'))");
        DB::statement("ALTER TABLE material_requests ADD CONSTRAINT material_requests_stage_check CHECK (current_stage IN ('draft', 'unit_manager', 'omaf', 'goods_services', 'warehouse', 'receipt', 'completed'))");
        DB::statement("ALTER TABLE material_requests ADD CONSTRAINT material_requests_outcome_check CHECK (fulfillment_outcome IS NULL OR fulfillment_outcome IN ('full', 'partial', 'none'))");
        DB::statement('ALTER TABLE material_request_items ADD CONSTRAINT material_request_items_quantity_check CHECK (requested_quantity > 0)');
        DB::statement("ALTER TABLE warehouse_receipts ADD CONSTRAINT warehouse_receipts_reference_type_check CHECK (reference_type IN ('invoice', 'note'))");
        DB::statement("ALTER TABLE warehouse_receipts ADD CONSTRAINT warehouse_receipts_currency_check CHECK (currency = 'BOB')");
        DB::statement('ALTER TABLE warehouse_receipts ADD CONSTRAINT warehouse_receipts_total_check CHECK (total_amount >= 0)');
        DB::statement('ALTER TABLE warehouse_receipt_lines ADD CONSTRAINT warehouse_receipt_lines_values_check CHECK (quantity > 0 AND unit_cost >= 0 AND subtotal >= 0)');
        DB::statement('CREATE UNIQUE INDEX warehouse_receipts_supplier_reference_unique ON warehouse_receipts (supplier_name, reference_type, reference_number)');
        DB::statement('ALTER TABLE warehouse_delivery_lines ADD CONSTRAINT warehouse_delivery_lines_values_check CHECK (delivered_quantity > 0 AND requested_quantity_snapshot > 0)');
        DB::statement('ALTER TABLE warehouse_delivery_lines ADD CONSTRAINT warehouse_delivery_lines_override_check CHECK (delivered_quantity <= requested_quantity_snapshot OR over_delivery_reason IS NOT NULL)');
        DB::statement("ALTER TABLE warehouse_stock_movements ADD CONSTRAINT warehouse_stock_movements_type_check CHECK (movement_type IN ('entry', 'exit', 'adjustment'))");
        DB::statement("ALTER TABLE warehouse_stock_movements ADD CONSTRAINT warehouse_stock_movements_source_check CHECK ((movement_type = 'entry' AND quantity_delta > 0 AND warehouse_receipt_line_id IS NOT NULL AND warehouse_delivery_line_id IS NULL) OR (movement_type = 'exit' AND quantity_delta < 0 AND warehouse_receipt_line_id IS NULL AND warehouse_delivery_line_id IS NOT NULL) OR (movement_type = 'adjustment' AND quantity_delta <> 0 AND warehouse_receipt_line_id IS NULL AND warehouse_delivery_line_id IS NULL AND reason IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_number_sequences');
        Schema::dropIfExists('warehouse_stock_movements');
        Schema::dropIfExists('warehouse_delivery_lines');
        Schema::dropIfExists('warehouse_deliveries');
        Schema::dropIfExists('warehouse_receipt_attachments');
        Schema::dropIfExists('warehouse_receipt_lines');
        Schema::dropIfExists('warehouse_receipts');
        Schema::dropIfExists('material_request_decisions');
        Schema::dropIfExists('material_request_items');
        Schema::dropIfExists('material_requests');
        Schema::dropIfExists('warehouse_items');
        Schema::dropIfExists('warehouse_categories');
        Schema::dropIfExists('measurement_units');
    }
};
