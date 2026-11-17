<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteCustomerAction
{
    /**
     * Delete a person from the list. Orders and loyalty history keep their rows; the customer
     * becomes anonymous and the mobile number is freed, so the same person can be added again later.
     */
    public function execute(Customer $customer, User $actor): Customer
    {
        DB::transaction(function () use ($customer, $actor): void {
            $customer->addresses()->delete();
            $customer->tokens()->delete();

            $customer->forceFill([
                'name' => __('customers.anonymized_name'),
                'mobile' => null,
                'email' => null,
                'google_id' => null,
                'password' => null,
                'birthday' => null,
                'is_active' => false,
                'deleted_by' => $actor->id,
            ])->save();

            $customer->delete();
        });

        activity()->performedOn($customer)->causedBy($actor)->log('customer.deleted');

        return $customer;
    }
}
