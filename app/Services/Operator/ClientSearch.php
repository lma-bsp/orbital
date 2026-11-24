<?php

declare(strict_types=1);

namespace App\Services\Operator;

use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Find clients by name, account number, or an alias account number.
 *
 * Searched server-side: an installation migrated from another system
 * has thousands of clients, too many to ship to the browser as options.
 */
final class ClientSearch
{
    /**
     * @return array<int, string> team id => "Name (#number)"
     */
    public function search(string $search, int $limit = 50): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        $query = Team::query()->where('personal_team', false);

        if (ctype_digit($search)) {
            $number = (int) $search;
            $query->where(fn (Builder $q) => $q
                ->where('account_number', $number)
                ->orWhereIn('id', DB::table('client_account_aliases')->where('account_number', $number)->select('team_id'))
                ->orWhere(DB::raw('CAST(account_number AS VARCHAR(20))'), 'like', $search.'%'));
        } else {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }

        return $query
            // Exact number matches first, then by number.
            ->orderByRaw('CASE WHEN account_number = ? THEN 0 ELSE 1 END', [ctype_digit($search) ? (int) $search : -1])
            ->orderBy('account_number')
            ->limit($limit)
            ->get(['id', 'name', 'account_number'])
            ->mapWithKeys(fn (Team $t) => [$t->id => self::label($t)])
            ->all();
    }

    public static function label(Team $team): string
    {
        return $team->account_number !== null
            ? "{$team->name} (#{$team->account_number})"
            : $team->name;
    }
}
