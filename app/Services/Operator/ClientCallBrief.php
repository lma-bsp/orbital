<?php

declare(strict_types=1);

namespace App\Services\Operator;

use App\Models\ClientLegacyRecord;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * What an operator needs in view while on a call for a client: how to
 * answer, the pinned note, the passcode, other account numbers, and the
 * info pages carried over from the old system.
 *
 * Shared by the single-account Workspace and the multi-call workspace.
 * Callers read it per render rather than keeping it in Livewire public
 * state, so the passcode is never part of a component snapshot.
 */
final class ClientCallBrief
{
    /**
     * @return array{greeting: ?string, operator_note: ?string, passcode: ?string, aliases: array<int, int>, info_pages: ?string}
     */
    public function for(Team $team): array
    {
        $infoPages = ClientLegacyRecord::query()
            ->withoutGlobalScope('team')
            ->where('team_id', $team->id)
            ->where('source', ClientLegacyRecord::SOURCE_AMTELCO)
            ->where('section', 'info_pages')
            ->first()?->payload['text'] ?? null;

        return [
            'greeting' => $team->greeting,
            'operator_note' => $team->operator_note,
            'passcode' => $team->passcode,
            'aliases' => DB::table('client_account_aliases')
                ->where('team_id', $team->id)
                ->orderBy('account_number')
                ->pluck('account_number')
                ->map(fn ($n) => (int) $n)
                ->all(),
            'info_pages' => is_string($infoPages) && trim($infoPages) !== '' ? $infoPages : null,
        ];
    }
}
