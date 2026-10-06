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

    protected function authorizeCompress(): void
    {
        $this->authorizePermission(ServiceProvider::PERMISSION_COMPRESS);
    }

    protected function canDelete(): bool
    {
        return $this->hasPermission(ServiceProvider::PERMISSION_DELETE);
    }

    protected function canCompress(): bool
    {
        return $this->hasPermission(ServiceProvider::PERMISSION_COMPRESS);
    }

    private function hasPermission(string $permission): bool
    {
        $user = User::current();

        return (bool) $user && ($user->isSuper() || $user->hasPermission($permission));
    }

    private function authorizePermission(string $permission): void
    {
        abort_unless($this->hasPermission($permission), 403);
    }
}
