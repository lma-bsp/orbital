<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One call on an operator's desk in the multi-call workspace.
 *
 * Not BelongsToTeam: a desk holds calls for several clients at once and
 * a call's client may not be known yet. Rows are scoped to their
 * operator (user_id) by MultiCallDesk instead.
 *
 * @property array{caller_name?: ?string, caller_phone?: ?string, reason?: ?string, urgency?: string}|null $draft
 */
class OperatorCall extends Model
{
    public const STATUS_RINGING = 'ringing';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_LIVE = 'live';

    public const STATUS_HELD = 'held';

    public const STATUS_ENDED = 'ended';

    /** A test call that never reached the softphone. */
    public const STATUS_MISSED = 'missed';

    public const STATUS_LABELS = [
        self::STATUS_RINGING => 'Ringing',
        self::STATUS_QUEUED => 'Queued',
        self::STATUS_LIVE => 'Connected',
        self::STATUS_HELD => 'On hold',
        self::STATUS_ENDED => 'Caller hung up',
        self::STATUS_MISSED => 'Didn\'t ring',
    ];

    protected $fillable = [
        'user_id',
        'team_id',
        'is_trial',
        'sip_call_id',
        'test_token',
        'caller_number',
        'caller_name',
        'called_number',
        'line_label',
        'status',
        'draft',
        'message_id',
        'arrived_at',
        'answered_at',
        'held_at',
        'ended_at',
        'message_saved_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_trial' => 'boolean',
            'draft' => 'array',
            'arrived_at' => 'datetime',
            'answered_at' => 'datetime',
            'held_at' => 'datetime',
            'ended_at' => 'datetime',
            'message_saved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** Calls still on the operator's desk (not yet closed). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    public function isWaiting(): bool
    {
        return in_array($this->status, [self::STATUS_RINGING, self::STATUS_QUEUED], true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst($this->status);
    }

    /** When the timer on this call's card counts from. */
    public function timerStartedAt(): Carbon
    {
        return match ($this->status) {
            self::STATUS_HELD => $this->held_at ?? $this->arrived_at,
            self::STATUS_LIVE => $this->answered_at ?? $this->arrived_at,
            self::STATUS_ENDED, self::STATUS_MISSED => $this->ended_at ?? $this->arrived_at,
            default => $this->arrived_at,
        };
    }
}
