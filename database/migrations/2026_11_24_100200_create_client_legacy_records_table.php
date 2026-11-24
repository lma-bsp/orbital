<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verbatim data carried over from the system a client was migrated from.
 *
 * One row per (client, source system, section): for an Amtelco import
 * that is the account row, its full setup report, info pages, forms,
 * dial lists and so on. Kept as the system exported it so that
 *
 *   - operators can still read the old info pages while Orbital's own
 *     structures (hours, directory, intake flows) are filled in;
 *   - later passes can derive structured data from it without going
 *     back to the export file;
 *   - nothing in the export is lost just because Orbital has no field
 *     for it yet.
 *
 * Re-running an import replaces a section's payload in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_legacy_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('source', 32);
            $table->string('section', 48);
            // The client's number in the source system, kept even if the
            // Orbital account number is later changed.
            $table->unsignedBigInteger('source_account_number')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->unique(['team_id', 'source', 'section']);
            $table->index(['source', 'source_account_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_legacy_records');
    }
};
