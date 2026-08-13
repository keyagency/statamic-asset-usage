<?php

namespace KeyAgency\AssetUsage\Http\Controllers\Concerns;

use KeyAgency\AssetUsage\ServiceProvider;
use Statamic\Facades\User;

trait AuthorizesAssetUsage
{
    protected function authorizeView(): void
    {
        $this->authorizePermission(ServiceProvider::PERMISSION_VIEW);
    }

    protected function authorizeDelete(): void
    {
        $this->authorizePermission(ServiceProvider::PERMISSION_DELETE);
    }

    protected function canDelete(): bool
    {
        $user = User::current();

        return (bool) $user && ($user->isSuper() || $user->hasPermission(ServiceProvider::PERMISSION_DELETE));
    }

    private function authorizePermission(string $permission): void
    {
        $user = User::current();

        abort_unless($user && ($user->isSuper() || $user->hasPermission($permission)), 403);
    }
}
