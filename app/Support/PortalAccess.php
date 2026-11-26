<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Portal\Pages\CallHistory;
use App\Filament\Portal\Pages\Dashboard;
use App\Filament\Portal\Pages\NoPortalAccess;
use App\Filament\Portal\Pages\TextConversations;
use App\Filament\Portal\Resources\RoleResource;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one place the customer portal asks "may the signed-in user see
 * this?" for the portal.view_* permissions.
 *
 * Checks go through Spatie (`$user->can()`), which is in teams mode:
 * SetPermissionsTeamContext pins the registrar to the user's
 * current_team_id on every web request, so a role only counts inside
 * the client it was granted in.
 *
 * Team owners (Team.user_id) get the same safety net as
 * RoleResource::viewerCanManageRoles(): a client admin who unticks a
 * view permission on every role cannot hide the portal from the
 * account's owner. Unlike portal.manage_roles, though, the owner
 * fallback for the view permissions stays inside the client's
 * permission ceiling (client_permission_grants). The ceiling is the
 * platform operator's decision — "this client does not get recording
 * playback" has to hold for the owner too, or the allow-list means
 * nothing for these permissions.
 */
final class PortalAccess
{
    public const VIEW_HOME = 'portal.view_home';

    public const VIEW_CALLS = 'portal.view_calls';

    public const VIEW_MESSAGES = 'portal.view_messages';

    public const VIEW_RECORDINGS = 'portal.view_recordings';

    public static function allows(string $permission, ?User $user = null): bool
    {
        $user ??= auth()->user();
        if (! $user instanceof User || ! $user->current_team_id) {
            return false;
        }

        if ($user->can($permission)) {
            return true;
        }

        return self::ownsCurrentTeam($user)
            && self::onCeiling((int) $user->current_team_id, $permission);
    }

    /**
     * Where to send a portal user: the first portal page they can open,
     * or the "no access yet" page when there is none. Used as the panel
     * home URL and by the Overview page when the user lacks view_home.
     */
    public static function homeUrl(): string
    {
        foreach ([Dashboard::class, CallHistory::class, TextConversations::class] as $page) {
            if ($page::canAccess()) {
                return $page::getUrl(panel: 'portal');
            }
        }

        if (RoleResource::canAccess()) {
            return RoleResource::getUrl('index', panel: 'portal');
        }

        return NoPortalAccess::getUrl(panel: 'portal');
    }

    private static function ownsCurrentTeam(User $user): bool
    {
        $team = $user->currentTeam;

        return $team !== null && (int) $team->user_id === (int) $user->id;
    }

    private static function onCeiling(int $teamId, string $permission): bool
    {
        return DB::table('client_permission_grants as g')
            ->join('permissions as p', 'p.id', '=', 'g.permission_id')
            ->where('g.team_id', $teamId)
            ->where('p.name', $permission)
            ->exists();
    }
}
