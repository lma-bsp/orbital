<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;

/**
 * One section of a client's data, verbatim from the system the client
 * was migrated from. See the create_client_legacy_records_table
 * migration.
 */
class ClientLegacyRecord extends Model
{
    use BelongsToTeam;

    public const SOURCE_AMTELCO = 'amtelco';

    protected $fillable = [
        'team_id',
        'source',
        'section',
        'source_account_number',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'source_account_number' => 'integer',
            'payload' => 'array',
        ];
    }
}
