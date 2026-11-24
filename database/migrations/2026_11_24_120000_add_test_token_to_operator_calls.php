<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A real test call placed from the admin trial page is recorded before
 * Asterisk rings the softphone. The token travels on the call as the
 * X-Orbital-Test-Call header, so the ringing call finds its record and
 * the client chosen for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operator_calls', function (Blueprint $table) {
            $table->string('test_token', 40)->nullable()->after('sip_call_id');
            $table->index('test_token');
        });
    }

    public function down(): void
    {
        Schema::table('operator_calls', function (Blueprint $table) {
            $table->dropIndex(['test_token']);
            $table->dropColumn('test_token');
        });
    }
};
