<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calls an operator is juggling in the multi-call workspace.
 *
 * One row per call on an operator's desk: ringing, waiting, on hold or
 * connected. Each row carries its own client and its own message draft,
 * so taking the next call never wipes the one before it. Rows live
 * here, not in component state, so they survive the operator panel's
 * SPA navigation and a page reload mid-call.
 *
 * Owned by the operator (user_id), not a client: one desk holds calls
 * for several clients at once. team_id is the client the call is for,
 * and stays null until it is known.
 *
 * is_trial rows come from the admin trial page. They are simulated:
 * no audio, and saving one never writes a Message to a client's inbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->boolean('is_trial')->default(false);
            // SIP Call-ID of the softphone leg, so later events for the
            // same call find this row. Null for trial calls.
            $table->string('sip_call_id')->nullable();
            $table->string('caller_number', 64)->nullable();
            $table->string('caller_name')->nullable();
            // The number the caller dialled, when the call carries it.
            $table->string('called_number', 64)->nullable();
            $table->string('line_label')->nullable();
            $table->string('status', 16);
            $table->json('draft')->nullable();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('arrived_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('held_at')->nullable();
            // The caller hung up. The tab stays open so the operator can
            // finish the message; closed_at takes it off the desk.
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('message_saved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'closed_at']);
            $table->index('sip_call_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_calls');
    }
};
