<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * The pieces of role management the web panel and the mobile app share: how a role is shown,
 * how a new one is named, the validation rules, and the rules every role must follow.
 */
class Roles
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Role $role): array
    {
        $permissions = $role->permissions->pluck('name')->values()->all();
        $isDefault = RoleDefaults::isDefault($role->name);

        return [
            'id' => $role->id,
            'name' => $role->name,
            'label' => self::label($role),
            'label_en' => $isDefault ? null : $role->label,
            'label_bn' => $isDefault ? null : $role->label_bn,
            'description' => $isDefault ? __('permissions.roles.'.$role->name.'.description') : $role->description,
            'is_default' => $isDefault,
            'is_locked' => RoleDefaults::isLocked($role->name),
            'all_branches' => (bool) $role->all_branches,
            'users_count' => (int) $role->users_count,
            'permissions' => $permissions,
            'modified' => RoleDefaults::differsFromDefault($role->name, $permissions),
        ];
    }

    public static function label(Role $role): string
    {
        if (RoleDefaults::isDefault($role->name)) {
            return __('permissions.roles.'.$role->name.'.name');
        }

        return (app()->getLocale() === 'bn' && $role->label_bn) ? $role->label_bn : ($role->label ?: $role->name);
    }

    /**
     * Every role can sign in to the panel.
     *
     * @param  array<int, string>  $permissions
     * @return array<int, string>
     */
    public static function withRequired(array $permissions): array
    {
        return array_values(array_unique([...$permissions, PermissionCatalog::REQUIRED]));
    }

    /**
     * A slug of the label that is not taken by a role and not reserved for a standard one.
     */
    public static function uniqueName(string $label): string
    {
        $base = Str::slug($label) ?: 'role';
        $name = $base;
        $n = 2;

        while (Role::query()->where('name', $name)->where('guard_name', 'staff')->exists() || RoleDefaults::isDefault($name)) {
            $name = $base.'-'.$n++;
        }

        return $name;
    }

    /**
     * @throws ValidationException
     */
    public static function guardEditable(Role $role): void
    {
        abort_unless($role->guard_name === 'staff', 404);

        if (RoleDefaults::isLocked($role->name)) {
            throw ValidationException::withMessages(['role' => __('roles.locked_error')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function storeRules(): array
    {
        return [
            'label' => ['required', 'string', 'max:80'],
            'label_bn' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'all_branches' => ['boolean'],
            'copy_from' => ['nullable', 'string', Rule::exists('roles', 'name')->where('guard_name', 'staff')],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::all())],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function updateRules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:80'],
            'label_bn' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'all_branches' => ['boolean'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::all())],
        ];
    }
}
