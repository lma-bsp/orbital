<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use Filament\Pages\Page;

/**
 * Landing page for a portal user whose roles grant none of the portal
 * pages. Without it the panel root would 403 on every visit and the
 * user would have no idea why.
 *
 * Open to every portal user (canAccess stays true) and never in the
 * navigation. Security and About remain reachable from the user menu.
 */
class NoPortalAccess extends Page
{
    protected static ?string $title = 'No access yet';

    protected static ?string $slug = 'no-access';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.portal.pages.no-portal-access';

    public string $tenantName = '';

    public function mount(): void
    {
        $this->tenantName = auth()->user()?->currentTeam?->name ?? '';
    }
}
