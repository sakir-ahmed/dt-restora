<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\RoleDefaults;
use App\Support\Rbac\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->map(fn (Role $role): array => Roles::present($role))
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

        $validated = $request->validate(Roles::storeRules());

        $name = Roles::uniqueName((string) $validated['label']);

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
        $role->syncPermissions(Roles::withRequired($permissions));

        activity()->performedOn($role)->causedBy($request->user('staff'))->log('roles.created');

        return back()->with('success', __('roles.created', ['role' => $validated['label']]));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('roles.manage');
        Roles::guardEditable($role);

        $validated = $request->validate(Roles::updateRules());

        $permissions = Roles::withRequired($validated['permissions']);

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

        return back()->with('success', __('roles.updated', ['role' => Roles::label($role)]));
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('roles.manage');
        Roles::guardEditable($role);

        if (RoleDefaults::isDefault($role->name)) {
            return back()->with('error', __('roles.cannot_delete_standard'));
        }

        if ($role->users()->exists()) {
            return back()->with('error', __('roles.cannot_delete_in_use', ['count' => $role->users()->count()]));
        }

        $label = Roles::label($role);
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
        Roles::guardEditable($role);

        if (! RoleDefaults::isDefault($role->name)) {
            return back()->with('error', __('roles.cannot_reset_custom'));
        }

        RoleDefaults::reset($role);

        activity()->performedOn($role)->causedBy($request->user('staff'))->log('roles.reset');

        return back()->with('success', __('roles.was_reset', ['role' => Roles::label($role)]));
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
}
