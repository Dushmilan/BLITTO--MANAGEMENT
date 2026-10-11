<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The full BLITTO PATENT DATABASE domain schema, designed once (issue #68).
 *
 * Tables mirror the FastAPI-era Pydantic models 1:1 (source of truth:
 * git tag pre-laravel-final-state, app/modules/<name>/models.py). uuid string
 * PKs match the Pydantic uuid4 ids; every FK is constrained and indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- identity -------------------------------------------------------
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->enum('role', ['user', 'director', 'md']);
            $table->string('user_code')->nullable()->unique();
            $table->string('password_hash')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            // Denormalized inventor display fields kept from the Pydantic model
            // (list-view filtering); inventors table is the normalized source.
            $table->string('inventor_name')->default('');
            $table->string('inventor_email')->default('');
            $table->enum('status', [
                'DRAFT', 'FILED', 'PUBLISHED', 'ACKNOWLEDGED', 'EXAMINATION',
                'DEFECT_SHEET_1', 'DEFECT_SHEET_2', 'DEFECT_SHEET_3',
                'GRANTED', 'REJECTED', 'MAINTENANCE',
            ])->default('DRAFT');
            $table->string('application_number')->nullable();
            $table->string('nipo_reference')->nullable();
            $table->string('technology_area')->default('');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('inventors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->string('inventor_name');
            $table->string('inventor_email');
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->index('application_id');
        });

        Schema::create('status_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->enum('old_status', [
                'DRAFT', 'FILED', 'PUBLISHED', 'ACKNOWLEDGED', 'EXAMINATION',
                'DEFECT_SHEET_1', 'DEFECT_SHEET_2', 'DEFECT_SHEET_3',
                'GRANTED', 'REJECTED', 'MAINTENANCE',
            ])->nullable();
            $table->enum('new_status', [
                'DRAFT', 'FILED', 'PUBLISHED', 'ACKNOWLEDGED', 'EXAMINATION',
                'DEFECT_SHEET_1', 'DEFECT_SHEET_2', 'DEFECT_SHEET_3',
                'GRANTED', 'REJECTED', 'MAINTENANCE',
            ]);
            $table->string('changed_by');
            $table->timestamp('changed_at');
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->index(['application_id', 'changed_at']);
        });

        // ---- docketing / prosecution ----------------------------------------
        Schema::create('deadlines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->enum('type', ['FILING', 'RESPONSE', 'MAINTENANCE_FEE']);
            $table->timestamp('due_date');
            $table->enum('status', ['OPEN', 'MET', 'MISSED'])->default('OPEN');
            $table->timestamp('created_at')->nullable();
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->index('application_id');
        });

        Schema::create('office_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->enum('kind', ['REJECTION', 'OBJECTION', 'ALLOWANCE']);
            $table->text('body');
            $table->timestamp('received_at');
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->index('application_id');
        });

        Schema::create('responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->uuid('office_action_id');
            $table->text('body');
            $table->timestamp('filed_at');
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->foreign('office_action_id')->references('id')->on('office_actions')->cascadeOnDelete();
            $table->index('application_id');
            $table->index('office_action_id');
        });

        // ---- filing workflow -------------------------------------------------
        Schema::create('filing_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id')->unique();
            $table->timestamp('filed_date')->nullable();
            $table->string('filed_by')->default('');
            $table->timestamp('nipo_acknowledged_at')->nullable();
            $table->string('nipo_acknowledged_by')->nullable();
            $table->integer('defect_sheet_count')->default(0);
            $table->timestamp('granted_at')->nullable();
            $table->string('granted_by')->nullable();
            $table->string('patent_number')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejected_by')->nullable();
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
        });

        Schema::create('defect_sheets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->integer('sheet_number');
            $table->text('description');
            $table->timestamp('created_at')->nullable();
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->unique(['application_id', 'sheet_number']);
        });

        // ---- documents --------------------------------------------------------
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->string('filename');
            $table->integer('file_size')->default(0);
            $table->string('content_ref');
            $table->string('uploaded_by');
            $table->timestamp('uploaded_at');
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->index('application_id');
        });

        // ---- cross-cutting ----------------------------------------------------
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('recipient_email');
            $table->string('subject');
            $table->text('body');
            $table->string('kind')->default('status_change');
            $table->timestamp('sent_at');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->nullable();
            $table->uuid('application_id')->nullable();
            $table->string('action');
            $table->timestamp('timestamp');
            $table->string('ip_address')->nullable();
            // Audit trail must survive application deletion.
            $table->foreign('application_id')->references('id')->on('applications')->nullOnDelete();
            $table->index('application_id');
        });
    }

    public function down(): void
    {
        // Reverse creation order (children before applications).
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('defect_sheets');
        Schema::dropIfExists('filing_records');
        Schema::dropIfExists('responses');
        Schema::dropIfExists('office_actions');
        Schema::dropIfExists('deadlines');
        Schema::dropIfExists('status_history');
        Schema::dropIfExists('inventors');
        Schema::dropIfExists('applications');
    }
};
