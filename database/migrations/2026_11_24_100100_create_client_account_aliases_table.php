<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Other account numbers a client is known by.
 *
 * Operators look clients up by number, and a client brought over from
 * another system often answers to more than one: the old system's
 * secondary numbers, a number from before a renumbering. These rows
 * make those findable without overloading teams.account_number, which
 * stays the one canonical, unique number.
 *
 * Deliberately NOT unique across clients: imported data can carry the
 * same alias on two clients, or an alias equal to another client's main
 * number. The importer reports those clashes for a person to resolve;
 * refusing to store them would only hide the problem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_account_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('account_number');
            // Where the alias came from, e.g. "amtelco:did". Null for
            // aliases a person entered.
            $table->string('source', 64)->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'account_number']);
            $table->index('account_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_account_aliases');
    }
};
