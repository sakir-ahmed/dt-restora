<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Customers\DeleteCustomerAction;
use App\Actions\Customers\UpdateCustomerAction;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('customers.view');

        $search = trim((string) $request->input('search', ''));

        $customers = Customer::query()
            ->visible()
            ->withSum('loyaltyEntries as points', 'points')
            // A deleted order is not a sale, so it does not count towards the customer's orders.
            ->withCount(['orders as orders_count' => fn ($query) => $query->where('status', '!=', OrderStatus::Void->value)])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('mobile', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'mobile' => $customer->mobile,
                'email' => $customer->email,
                'points' => (int) ($customer->points ?? 0),
                'orders_count' => (int) $customer->orders_count,
                'is_active' => $customer->is_active,
                'is_deleted' => $customer->trashed(),
            ]);

        return Inertia::render('admin/customers/Index', [
            'customers' => $customers,
            'search' => $search,
            'canManage' => (bool) $request->user('staff')?->can('customers.manage'),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('customers.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'mobile' => ['nullable', 'string', 'max:20', Rule::unique('customers', 'mobile')->ignore($customer->id)],
            'email' => ['nullable', 'string', 'email:filter', 'max:100', Rule::unique('customers', 'email')->ignore($customer->id)],
            'is_active' => ['required', 'boolean'],
        ], [], [
            'name' => __('customers.name'),
            'mobile' => __('customers.mobile'),
        ]);

        $mobile = trim((string) ($validated['mobile'] ?? '')) !== '' ? normalize_bd_mobile((string) $validated['mobile']) : null;

        if ($mobile !== null && ! is_bd_mobile($mobile)) {
            throw ValidationException::withMessages(['mobile' => __('web.err_mobile')]);
        }

        app(UpdateCustomerAction::class)->execute($customer, [
            'name' => $validated['name'],
            'mobile' => $mobile,
            'email' => $validated['email'] ?? null,
            'is_active' => $validated['is_active'],
        ], $request->user('staff'));

        return back()->with('success', __('customers.saved'));
    }

    /**
     * Delete a person from the list. Orders and loyalty history keep their rows; the customer
     * becomes anonymous and the mobile number is freed, so the same person can be added again later.
     */
    public function destroy(Request $request, Customer $customer, DeleteCustomerAction $action): RedirectResponse
    {
        $this->authorize('customers.manage');

        $action->execute($customer, $request->user('staff'));

        return back()->with('success', __('customers.deleted'));
    }
}
