<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Api\Concerns\InteractsWithApi;
use App\Http\Controllers\Api\Controller;
use App\Models\User;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\RoleDefaults;
use App\Support\Rbac\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles and what each may do, for the mobile app: the same list, permission matrix and rules as
 * the web panel's Roles screen, over the same support code.
 */
class RoleApiController extends Controller
{
    use InteractsWithApi;

    public function index(Request $request): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'roles.manage');

        $counts = $this->usersByRole();

        $roles = Role::query()
            ->where('guard_name', 'staff')
            ->with('permissions:id,name')
            ->orderBy('id')
            ->get()
            ->map(function (Role $role) use ($counts): array {
                $role->users_count = $counts[$role->id] ?? 0;

                return Roles::present($role);
            })
            ->values()
            ->all();

        return $this->success([
            'roles' => $roles,
            'groups' => PermissionCatalog::groups(),
            'required' => PermissionCatalog::REQUIRED,
            'defaults' => collect(RoleDefaults::names())->mapWithKeys(fn (string $name): array => [$name => RoleDefaults::permissionsFor($name)])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'roles.manage');

        $validated = $request->validate(Roles::storeRules());

        $permissions = isset($validated['copy_from']) && ! isset($validated['permissions'])
            ? Role::findByName($validated['copy_from'], 'staff')->permissions->pluck('name')->all()
            : ($validated['permissions'] ?? []);

        $role = Role::query()->create(['name' => Roles::uniqueName((string) $validated['label']), 'guard_name' => 'staff']);
        $role->forceFill([
            'label' => $validated['label'],
            'label_bn' => $validated['label_bn'] ?? null,
            'description' => $validated['description'] ?? null,
            'all_branches' => (bool) ($validated['all_branches'] ?? false),
        ])->save();
        $role->syncPermissions(Roles::withRequired($permissions));

        activity()->performedOn($role)->causedBy($user)->log('roles.created');

        return $this->success($this->presentFresh($role), __('roles.created', ['role' => $validated['label']]), 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'roles.manage');
        Roles::guardEditable($role);

        $validated = $request->validate(Roles::updateRules());

        $permissions = Roles::withRequired($validated['permissions']);

        // Nobody may take the Roles screen away from their own account.
        if (! in_array('roles.manage', $permissions, true) && $user->hasRole($role->name) && ! $user->hasRole('super-admin')) {
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

        activity()->performedOn($role)->causedBy($user)->withProperties(['permissions' => $permissions])->log('roles.updated');

        return $this->success($this->presentFresh($role), __('roles.updated', ['role' => Roles::label($role)]));
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'roles.manage');
        Roles::guardEditable($role);

        if (RoleDefaults::isDefault($role->name)) {
            throw ValidationException::withMessages(['role' => __('roles.cannot_delete_standard')]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages(['role' => __('roles.cannot_delete_in_use', ['count' => $role->users()->count()])]);
        }

        $label = Roles::label($role);
        $role->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        activity()->causedBy($user)->withProperties(['role' => $role->name])->log('roles.deleted');

        return $this->success(['id' => $role->id, 'deleted' => true], __('roles.deleted', ['role' => $label]));
    }

    /**
     * Put one standard role back to its default permissions.
     */
    public function reset(Request $request, Role $role): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'roles.manage');
        Roles::guardEditable($role);

        if (! RoleDefaults::isDefault($role->name)) {
            throw ValidationException::withMessages(['role' => __('roles.cannot_reset_custom')]);
        }

        RoleDefaults::reset($role);

        activity()->performedOn($role)->causedBy($user)->log('roles.reset');

        return $this->success($this->presentFresh($role), __('roles.was_reset', ['role' => Roles::label($role)]));
    }

    /**
     * Put every standard role back to its defaults and bring back any that were removed. Roles
     * made by the owner are left as they are.
     */
    public function resetAll(Request $request): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'roles.manage');

        RoleDefaults::install(true);

        activity()->causedBy($user)->log('roles.reset_all');

        return $this->success(['reset' => true], __('roles.all_were_reset'));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentFresh(Role $role): array
    {
        $counts = $this->usersByRole();
        $role->users_count = $counts[$role->id] ?? 0;

        return Roles::present($role->load('permissions:id,name'));
    }

    /**
     * spatie's role->users() resolves the user model through the default guard, which reads
     * 'sanctum' on a token request, so the counts come from the pivot table instead.
     *
     * @return array<int, int>
     */
    private function usersByRole(): array
    {
        return DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->groupBy('role_id')
            ->selectRaw('role_id, COUNT(*) as users_count')
            ->pluck('users_count', 'role_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }
}
