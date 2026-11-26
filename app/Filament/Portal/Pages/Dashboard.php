<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Models\CallLog;
use App\Support\PortalAccess;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Http\Exceptions\HttpResponseException;
use UnitEnum;

/**
 * Client user's home page on the portal. Shows a small summary of
 * recent call activity so they land on something useful; the full
 * call history lives on {@see CallHistory}.
 *
 * Extends Filament's base Dashboard so the page auto-registers at the
 * panel root (`/portal`) instead of getting its own slug.
 *
 * Queries bypass the BelongsToTeam global scope (we don't rely on it
 * to be set correctly for the portal context) and filter explicitly
 * on `current_team_id` for defense in depth.
 *
 * Gated on portal.view_home. Because this page IS the panel root, a
 * user without it is redirected to the first portal page they can
 * open (see PortalAccess::homeUrl()) instead of being shown a 403 on
 * every login. The latest-activity list names callers, so it also
 * needs portal.view_calls; the counts alone do not.
 */
class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static string|UnitEnum|null $navigationGroup = 'Activity';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Overview';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.portal.pages.dashboard';

    public array $recentCalls = [];

    public int $callsTodayCount = 0;

    public string $tenantName = '';

    public bool $showRecentCalls = false;

    public static function canAccess(): bool
    {
        return PortalAccess::allows(PortalAccess::VIEW_HOME);
    }

    /**
     * Replaces Filament's abort(403) on first load with a redirect, so
     * landing on /portal without view_home is never a dead end.
     * Livewire follow-up requests (hydrate) still 403 as usual.
     */
    public function mountCanAuthorizeAccess(): void
    {
        if (! static::canAccess()) {
            throw new HttpResponseException(redirect()->to(PortalAccess::homeUrl()));
        }
    }

    public function mount(): void
    {
        $user = auth()->user();
        $teamId = (int) ($user->current_team_id ?? 0);
        $this->tenantName = $user->currentTeam?->name ?? 'Unknown Client';

        $this->showRecentCalls = PortalAccess::allows(PortalAccess::VIEW_CALLS);

        $this->recentCalls = ! $this->showRecentCalls ? [] : CallLog::query()
            ->where('team_id', $teamId)
            ->orderByDesc('started_at')
            ->limit(5)
            ->get(['id', 'direction', 'from_number', 'to_number', 'status', 'duration_seconds', 'started_at'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'direction' => $row->direction,
                'from_number' => $row->from_number,
                'to_number' => $row->to_number,
                'status' => $row->status,
                'duration_seconds' => $row->duration_seconds,
                'started_at' => optional($row->started_at)->format('M j, g:i a'),
            ])
            ->all();

        $this->callsTodayCount = CallLog::query()
            ->where('team_id', $teamId)
            ->whereDate('started_at', today())
            ->count();
    }
}
