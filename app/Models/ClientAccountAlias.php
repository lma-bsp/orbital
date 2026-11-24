<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;

/**
 * Another account number a client answers to. See the
 * create_client_account_aliases_table migration for why these are not
 * unique across clients.
 */
class ClientAccountAlias extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'account_number',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'account_number' => 'integer',
        ];
    }
}
