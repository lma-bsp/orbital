<?php

declare(strict_types=1);

namespace App\Models\Passport;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Scope;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Passport Client model subclass that auto-approves the authorization
 * step for in-platform OIDC clients. Registered as the Passport client
 * model via `Passport::useClientModel()` in AppServiceProvider.
 *
 * Why: Passport's default `AuthorizationController` renders a consent
 * view (`Laravel\Passport\Contracts\AuthorizationViewResponse`) when a
 * user first hits `/oauth/authorize` for a given client. Passport 13
 * ships the contract but does NOT bind a default implementation, so
 * hitting the authorize endpoint with any non-trusted client throws
 * `BindingResolutionException`. Our in-platform SSO tools (pgAdmin,
 * MinIO Console) should never show a consent screen anyway — they're
 * first-party infra, the user already logged into Laravel, and the
 * OAuth2 consent step is third-party-integration UX that doesn't
 * apply here.
 *
 * The override flips `skipsAuthorization` to true for clients whose
 * `name` matches the set registered by `SsoSecretsBootstrapper`, but
 * only for a user allowed into that tool: super-admin, or the tool's
 * `tooling.*` permission. Anyone else gets a 403 right here. Returning
 * false would not be enough, because the fallback consent screen has
 * an Authorize button that would let them approve themselves; and
 * throwing before Passport's `hasGrantedScopes()` check also stops a
 * user who lost the permission from riding an earlier grant.
 *
 * Any hypothetical future third-party client (unknown name) still runs
 * through the normal consent flow — where it'll either need a view
 * binding OR an explicit entry in this allowlist.
 */
class FirstPartyClient extends Client
{
    /**
     * Stable client names that auto-approve, each with the permission
     * a non-super-admin needs to sign in to it. Mirrors the OIDC_CLIENTS
     * constant on SsoSecretsBootstrapper — keep them in lockstep. If
     * you add a new first-party OIDC integration, register its
     * client via the bootstrapper AND add the name here.
     *
     * @var array<string, string>
     */
    private const FIRST_PARTY_CLIENTS = [
        'pgadmin' => 'tooling.pgadmin',
    ];

    /**
     * @param  Scope[]  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        $permission = self::FIRST_PARTY_CLIENTS[$this->name] ?? null;
        if ($permission === null) {
            return false;
        }

        abort_unless(self::mayUse($user, $permission), 403, "Missing required permission: {$permission}");

        return true;
    }

    private static function mayUse(Authenticatable $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if (! method_exists($user, 'hasPermissionTo')) {
            return false;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
