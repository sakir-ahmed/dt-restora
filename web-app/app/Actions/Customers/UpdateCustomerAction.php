<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\User;

class UpdateCustomerAction
{
    /**
     * @param  array{name: string, mobile: ?string, email: ?string, is_active: bool}  $data
     */
    public function execute(Customer $customer, array $data, User $actor): Customer
    {
        $customer->update([
            'name' => $data['name'],
            'mobile' => $data['mobile'],
            'email' => $data['email'],
            'is_active' => $data['is_active'],
        ]);

        activity()->performedOn($customer)->causedBy($actor)->log('customer.updated');

        return $customer;
    }
}
