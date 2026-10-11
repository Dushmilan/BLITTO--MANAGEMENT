<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('status_history', function (Blueprint $table) {
            // CONTEXT.md invariant 5: every state change logs actor, timestamp, AND reason.
            $table->string('reason')->after('changed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('status_history', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
