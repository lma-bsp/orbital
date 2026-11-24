<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three things an operator needs in front of them the moment a client's
 * call lands, carried over from how answering services have always run:
 *
 *   greeting       How to answer the line ("Dr. Smith's office, this is
 *                  ___, how may I help you?"). Free text; it is often an
 *                  instruction to the operator rather than a script.
 *
 *   operator_note  A short pinned note shown above everything else in
 *                  the workspace: "TRICKY", "PROCEDURES", "DR ON
 *                  VACATION UNTIL 12/1". Amtelco calls this the client
 *                  status.
 *
 *   passcode       What a caller (usually the client's own staff) says
 *                  to prove who they are before messages are read back.
 *                  Encrypted at rest through the model cast, so the
 *                  column is text, not a short string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->text('greeting')->nullable();
            $table->string('operator_note', 255)->nullable();
            $table->text('passcode')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['greeting', 'operator_note', 'passcode']);
        });
    }
};
