<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\RoleDefaults;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and what each may do. The standard restaurant roles come ready made and can be reset to their defaults;
 * an owner can also make their own roles. The owner and super-admin roles always hold everything.
 */
class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('roles.manage');

        $roles = Role::query()
            ->where('guard_name', 'staff')
            ->withCount('users')
            ->with('permissions:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role): array => $this->present($role))
            ->values();

        return Inertia::render('admin/roles/Index', [
            'roles' => $roles,
            'groups' => PermissionCatalog::groups(),
            'required' => PermissionCatalog::REQUIRED,
            'defaults' => collect(RoleDefaults::names())->mapWithKeys(fn (string $name): array => [$name => RoleDefaults::permissionsFor($name)])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('roles.manage');

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'label_bn' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'all_branches' => ['boolean'],
            'copy_from' => ['nullable', 'string', Rule::exists('roles', 'name')->where('guard_name', 'staff')],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::all())],
        ]);

        $name = $this->uniqueName((string) $validated['label']);

        $permissions = isset($validated['copy_from']) && ! isset($validated['permissions'])
            ? Role::findByName($validated['copy_from'], 'staff')->permissions->pluck('name')->all()
            : ($validated['permissions'] ?? []);

        $role = Role::query()->create(['name' => $name, 'guard_name' => 'staff']);
        $role->forceFill([
            'label' => $validated['label'],
            'label_bn' => $validated['label_bn'] ?? null,
            'description' => $validated['description'] ?? null,
            'all_branches' => (bool) ($validated['all_branches'] ?? false),
        ])->save();
        $role->syncPermissions($this->withRequired($permissions));

        activity()->performedOn($role)->causedBy($request->user('staff'))->log('roles.created');

        return back()->with('success', __('roles.created', ['role' => $validated['label']]));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('roles.manage');
        $this->guardEditable($role);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:80'],
            'label_bn' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'all_branches' => ['boolean'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::all())],
        ]);

        $permissions = $this->withRequired($validated['permissions']);

        // Nobody may take the Roles screen away from their own account.
        $actor = $request->user('staff');

        if (! in_array('roles.manage', $permissions, true) && $actor->hasRole($role->name) && ! $actor->hasRole('super-admin')) {
            throw ValidationException::withMessages(['permissions' => __('roles.cannot_remove_own_access')]);
        }

        $attributes = ['all_branches' => (bool) ($validated['all_branches'] ?? $role->all_branches)];

        // A standard role keeps its name and wording; only a custom role can be renamed.
        if (! RoleDefaults::isDefault($role->name)) {
            $attributes += [
                'label' => ($validated['label'] ?? null) ?: $role->label,
                'label_bn' => $validated['label_bn'] ?? null,
                'description' => $validated['description'] ?? null,
            ];
        }

        $role->forceFill($attributes)->save();
        $role->syncPermissions($permissions);

        activity()->performedOn($role)->causedBy($actor)->withProperties(['permissions' => $permissions])->log('roles.updated');

        return back()->with('success', __('roles.updated', ['role' => $this->label($role)]));
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('roles.manage');
        $this->guardEditable($role);

        if (RoleDefaults::isDefault($role->name)) {
            return back()->with('error', __('roles.cannot_delete_standard'));
        }

        if ($role->users()->exists()) {
            return back()->with('error', __('roles.cannot_delete_in_use', ['count' => $role->users()->count()]));
        }

        $label = $this->label($role);
        $role->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        activity()->causedBy($request->user('staff'))->withProperties(['role' => $role->name])->log('roles.deleted');

        return back()->with('success', __('roles.deleted', ['role' => $label]));
    }

    /**
     * Put one standard role back to its default permissions.
     */
    public function reset(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('roles.manage');
        $this->guardEditable($role);

        if (! RoleDefaults::isDefault($role->name)) {
            return back()->with('error', __('roles.cannot_reset_custom'));
        }

        RoleDefaults::reset($role);

        activity()->performedOn($role)->causedBy($request->user('staff'))->log('roles.reset');

        return back()->with('success', __('roles.was_reset', ['role' => $this->label($role)]));
    }

    /**
     * Put every standard role back to its defaults and bring back any that were removed. Roles made by the
     * owner are left as they are.
     */
    public function resetAll(Request $request): RedirectResponse
    {
        $this->authorize('roles.manage');

        RoleDefaults::install(true);

        activity()->causedBy($request->user('staff'))->log('roles.reset_all');

        return back()->with('success', __('roles.all_were_reset'));
    }

    private function guardEditable(Role $role): void
    {
        abort_unless($role->guard_name === 'staff', 404);

        if (RoleDefaults::isLocked($role->name)) {
            throw ValidationException::withMessages(['role' => __('roles.locked_error')]);
        }
    }

    /**
     * Every role can sign in to the panel.
     *
     * @param  array<int, string>  $permissions
     * @return array<int, string>
     */
    private function withRequired(array $permissions): array
    {
        return array_values(array_unique([...$permissions, PermissionCatalog::REQUIRED]));
    }

    private function uniqueName(string $label): string
    {
        $base = Str::slug($label) ?: 'role';
        $name = $base;
        $n = 2;

        while (Role::query()->where('name', $name)->where('guard_name', 'staff')->exists() || RoleDefaults::isDefault($name)) {
            $name = $base.'-'.$n++;
        }

        return $name;
    }

    private function label(Role $role): string
    {
        if (RoleDefaults::isDefault($role->name)) {
            return __('permissions.roles.'.$role->name.'.name');
        }

        return (app()->getLocale() === 'bn' && $role->label_bn) ? $role->label_bn : ($role->label ?: $role->name);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Role $role): array
    {
        $permissions = $role->permissions->pluck('name')->values()->all();
        $isDefault = RoleDefaults::isDefault($role->name);

        return [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $this->label($role),
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
}
