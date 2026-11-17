<?php

declare(strict_types=1);

use App\Models\AttendanceDevice;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->branch = Branch::factory()->create(['code' => 'BR01']);
    $this->owner = User::factory()->role('owner')->create();
    $this->token = $this->owner->createToken('staff', ['staff'])->plainTextToken;
    $this->base = '/api/v1/branches/'.$this->branch->id;
    $this->employee = Employee::query()->create([
        'branch_id' => $this->branch->id, 'name' => 'Karim', 'designation' => 'Cook', 'monthly_salary' => 15000, 'is_active' => true,
    ]);
});

it('shows a customer with points and order history', function (): void {
    $customer = Customer::query()->create(['name' => 'Rahim', 'mobile' => '01712345678']);

    $this->withToken($this->token)->getJson('/api/v1/customers/'.$customer->id)
        ->assertOk()
        ->assertJsonPath('data.name', 'Rahim')
        ->assertJsonPath('data.orders_count', 0)
        ->assertJsonStructure(['data' => ['points', 'orders']]);
});

it('lists, edits and switches off an employee', function (): void {
    $this->withToken($this->token)->getJson($this->base.'/staff')->assertOk()
        ->assertJsonPath('data.employees.0.name', 'Karim')
        ->assertJsonStructure(['data' => ['users', 'roles', 'pay_bases']]);

    $this->withToken($this->token)->putJson($this->base.'/employees/'.$this->employee->id, [
        'name' => 'Karim Uddin', 'designation' => 'Head cook', 'monthly_salary' => 18000,
    ])->assertOk()->assertJsonPath('data.designation', 'Head cook');

    $this->withToken($this->token)->deleteJson($this->base.'/employees/'.$this->employee->id)->assertOk();
    expect($this->employee->fresh()->is_active)->toBeFalse();
});

it('creates and updates a system user with a role and refuses to switch off yourself', function (): void {
    $id = $this->withToken($this->token)->postJson($this->base.'/users', [
        'name' => 'Sara', 'username' => 'sara', 'password' => 'secret-pass-1', 'roles' => ['cashier'],
    ])->assertCreated()->json('data.id');

    $this->withToken($this->token)->putJson($this->base.'/users/'.$id, [
        'name' => 'Sara Khan', 'username' => 'sara', 'roles' => ['waiter'],
    ])->assertOk()->assertJsonPath('data.roles.0', 'waiter');

    $this->withToken($this->token)->deleteJson($this->base.'/users/'.$this->owner->id)->assertUnprocessable();
    $this->withToken($this->token)->deleteJson($this->base.'/users/'.$id)->assertOk();
    expect(User::query()->find($id)->is_active)->toBeFalse();
});

it('records attendance by hand and manages devices', function (): void {
    // A fixed evening clock, so the day's 9-to-5 times are always in the past.
    $this->travelTo(now()->setTime(18, 0));
    $this->withToken($this->token)->postJson($this->base.'/attendance', [
        'employee_id' => $this->employee->id,
        'work_date' => now()->toDateString(),
        'status' => 'present',
        'clock_in' => now()->setTime(9, 0)->toIso8601String(),
        'clock_out' => now()->setTime(17, 0)->toIso8601String(),
    ])->assertCreated();

    $rows = $this->withToken($this->token)->getJson($this->base.'/attendance')->assertOk()->json('data.rows');
    expect($rows[0]['status'])->toBe('present')->and($rows[0]['hours'])->toBe('8.00');

    $device = $this->withToken($this->token)->postJson($this->base.'/attendance/devices', ['name' => 'Front door', 'serial' => 'SN1'])
        ->assertCreated();
    expect($device->json('data.token'))->toHaveLength(48);

    $id = $device->json('data.id');
    $this->withToken($this->token)->putJson($this->base.'/attendance/devices/'.$id, ['name' => 'Front gate', 'serial' => 'SN1', 'is_active' => true])->assertOk();
    $old = AttendanceDevice::query()->find($id)->token_hash;
    $this->withToken($this->token)->postJson($this->base.'/attendance/devices/'.$id.'/token')->assertOk();
    expect(AttendanceDevice::query()->find($id)->token_hash)->not->toBe($old);
});

it('records and removes a salary advance and serves a salary slip', function (): void {
    $this->withToken($this->token)->postJson($this->base.'/payroll/advances', [
        'employee_id' => $this->employee->id, 'amount' => 2000, 'advanced_on' => now()->toDateString(),
    ])->assertCreated();

    $advances = $this->withToken($this->token)->getJson($this->base.'/payroll/advances')->assertOk()->json('data.advances');
    expect($advances)->toHaveCount(1);

    $this->withToken($this->token)->postJson($this->base.'/payroll/advances/'.$advances[0]['id'].'/delete', ['reason_code' => 'entry_mistake'])->assertOk();

    $this->withToken($this->token)->get($this->base.'/payroll/slips/'.$this->employee->id)->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('deactivates a coupon and counts redemptions in the list', function (): void {
    $coupon = Coupon::query()->create(['code' => 'EID', 'type' => 'percent', 'value' => 10, 'is_active' => true]);

    $this->withToken($this->token)->getJson('/api/v1/coupons')->assertOk()->assertJsonPath('data.0.redemptions_count', 0);
    $this->withToken($this->token)->deleteJson('/api/v1/coupons/'.$coupon->id)->assertOk();

    expect($coupon->fresh()->is_active)->toBeFalse();
});

it('keeps people management to permitted roles', function (): void {
    $waiter = User::factory()->role('waiter', $this->branch->id)->create();
    $token = $waiter->createToken('staff', ['staff'])->plainTextToken;

    $this->withToken($token)->postJson($this->base.'/users', ['name' => 'X', 'password' => 'secret-pass-1', 'roles' => ['cashier']])->assertForbidden();
});

it('edits a customer through the api and refuses a bad mobile', function (): void {
    $customer = Customer::query()->create(['name' => 'Rahim', 'mobile' => '01712345678']);

    $this->withToken($this->token)->putJson('/api/v1/customers/'.$customer->id, [
        'name' => 'Rahim Uddin', 'mobile' => '01812345678', 'email' => 'rahim@example.com', 'is_active' => true,
    ])->assertOk()->assertJsonPath('data.id', $customer->id);

    expect($customer->fresh()->name)->toBe('Rahim Uddin')
        ->and($customer->fresh()->mobile)->toBe('8801812345678');

    $this->withToken($this->token)->putJson('/api/v1/customers/'.$customer->id, [
        'name' => 'Rahim', 'mobile' => '123', 'email' => null, 'is_active' => true,
    ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('deletes a customer through the api, keeping their orders and freeing the mobile', function (): void {
    $customer = Customer::query()->create(['name' => 'Karim', 'mobile' => '01711111111']);

    Order::query()->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'invoice_no' => 'A1',
        'order_type' => 'takeaway',
        'status' => 'paid',
        'subtotal' => '100.00',
        'total' => '100.00',
    ]);

    $this->withToken($this->token)->deleteJson('/api/v1/customers/'.$customer->id)->assertOk();

    expect($customer->fresh()->trashed())->toBeTrue()
        ->and($customer->fresh()->mobile)->toBeNull()
        ->and(Order::query()->where('customer_id', $customer->id)->count())->toBe(1)
        ->and(Customer::query()->where('mobile', '8801711111111')->count())->toBe(0);
});

it('keeps customer editing and deleting behind customers.manage', function (): void {
    $customer = Customer::query()->create(['name' => 'Salam', 'mobile' => '01722222222']);
    $cashier = User::factory()->role('cashier', $this->branch->id)->create();
    $token = $cashier->createToken('staff', ['staff'])->plainTextToken;

    $this->withToken($token)->putJson('/api/v1/customers/'.$customer->id, [
        'name' => 'X', 'mobile' => '', 'email' => null, 'is_active' => true,
    ])->assertForbidden();

    $this->withToken($token)->deleteJson('/api/v1/customers/'.$customer->id)->assertForbidden();

    expect($customer->fresh()->trashed())->toBeFalse();
});
