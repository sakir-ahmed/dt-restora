<?php

declare(strict_types=1);

use App\Actions\Sales\CreateOrderAction;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class, UnitSeeder::class]);

    $this->branch = Branch::factory()->create(['code' => 'BR01']);
    $this->owner = User::factory()->role('owner', $this->branch->id)->create();
    $this->manager = User::factory()->role('branch-manager', $this->branch->id)->create();
    $this->cashier = User::factory()->role('cashier', $this->branch->id)->create();
    $this->ownerToken = $this->owner->createToken('staff', ['staff'])->plainTextToken;
    $this->managerToken = $this->manager->createToken('staff', ['staff'])->plainTextToken;
    $this->cashierToken = $this->cashier->createToken('staff', ['staff'])->plainTextToken;

    $this->item = MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'menu_category_id' => MenuCategory::factory()->create(['branch_id' => $this->branch->id])->id,
        'base_price' => '400.00',
    ]);
});

function apiOrder(): Order
{
    return app(CreateOrderAction::class)->execute(
        branch: test()->branch,
        type: OrderType::Takeaway,
        lines: [['menu_item_id' => test()->item->id, 'quantity' => 1]],
        cashier: test()->cashier,
    );
}

it('pays a whole bill with one wallet line, completing at once when verification is off', function (): void {
    $order = apiOrder();

    $response = $this->withToken($this->cashierToken)
        ->withHeaders(['Idempotency-Key' => uniqid('test-', true)])
        ->postJson("/api/v1/branches/{$this->branch->id}/orders/{$order->id}/payments", [
            'lines' => [['method' => 'bkash', 'amount' => (string) $order->total, 'reference' => 'TRX-APP-1']],
        ])
        ->assertOk()->json();

    expect($response['message'])->toBe('bKash payment recorded.')
        ->and($response['data']['status'])->toBe('paid')
        ->and($response['data']['payments'][0]['status'])->toBe(Payment::STATUS_COMPLETED)
        ->and($response['data']['payments'][0]['reference'])->toBe('TRX-APP-1')
        ->and($order->fresh()->status->value)->toBe('paid');
});

it('records one wallet line as waiting when the branch switched verification on', function (): void {
    $this->branch->update(['pos_settings' => ['wallet_verification_enabled' => true]]);

    $order = apiOrder();

    $response = $this->withToken($this->cashierToken)
        ->withHeaders(['Idempotency-Key' => uniqid('test-', true)])
        ->postJson("/api/v1/branches/{$this->branch->id}/orders/{$order->id}/payments", [
            'lines' => [['method' => 'bkash', 'amount' => (string) $order->total]],
        ])
        ->assertOk()->json();

    expect($response['message'])->toBe('Wallet payment recorded. Waiting for manager verification.')
        ->and($response['data']['payments'][0]['status'])->toBe(Payment::STATUS_PENDING_VERIFICATION)
        ->and($order->fresh()->status->value)->not->toBe('paid');
});

it('settles a whole bill with one card or one cash line', function (): void {
    $card = apiOrder();

    $this->withToken($this->cashierToken)
        ->withHeaders(['Idempotency-Key' => uniqid('test-', true)])
        ->postJson("/api/v1/branches/{$this->branch->id}/orders/{$card->id}/payments", [
            'lines' => [['method' => 'card', 'amount' => (string) $card->total, 'reference' => 'SLIP-9']],
        ])->assertOk();

    expect($card->fresh()->status->value)->toBe('paid')
        ->and($card->payments->first()->method)->toBe('card')
        ->and((float) $card->payments->first()->amount)->toBe((float) $card->total);

    $cash = apiOrder();

    $this->withToken($this->cashierToken)
        ->withHeaders(['Idempotency-Key' => uniqid('test-', true)])
        ->postJson("/api/v1/branches/{$this->branch->id}/orders/{$cash->id}/payments", [
            'lines' => [['method' => 'cash', 'amount' => (string) $cash->total]],
        ])->assertOk();

    expect($cash->fresh()->status->value)->toBe('paid')
        ->and((float) $cash->payments->first()->change_due)->toBe(0.0);
});

it('lets a manager verify or reject a waiting wallet payment from the app', function (): void {
    $this->branch->update(['pos_settings' => ['wallet_verification_enabled' => true]]);

    $order = apiOrder();

    $this->withToken($this->cashierToken)
        ->withHeaders(['Idempotency-Key' => uniqid('test-', true)])
        ->postJson("/api/v1/branches/{$this->branch->id}/orders/{$order->id}/payments", [
            'lines' => [['method' => 'bkash', 'amount' => (string) $order->total]],
        ])->assertOk();

    $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

    // A cashier cannot verify.
    $this->withToken($this->cashierToken)->postJson("/api/v1/payments/{$payment->id}/approve")->assertForbidden();

    app('auth')->forgetGuards();

    $approved = $this->withToken($this->managerToken)->postJson("/api/v1/payments/{$payment->id}/approve")->assertOk()->json();

    expect($approved['data']['status'])->toBe('paid')
        ->and($payment->fresh()->status)->toBe(Payment::STATUS_COMPLETED);

    // The rejected twin: recording again is impossible on a paid order, so reject a fresh partial.
    $this->branch->update(['pos_settings' => ['wallet_verification_enabled' => true]]);
    $second = apiOrder();

    $this->withToken($this->cashierToken)
        ->withHeaders(['Idempotency-Key' => uniqid('test-', true)])
        ->postJson("/api/v1/branches/{$this->branch->id}/orders/{$second->id}/payments", [
            'lines' => [['method' => 'bkash', 'amount' => '100']],
        ])->assertOk();

    $pending = Payment::query()->where('order_id', $second->id)->firstOrFail();

    $this->withToken($this->managerToken)->postJson("/api/v1/payments/{$pending->id}/reject")->assertOk();

    expect($pending->fresh()->status)->toBe(Payment::STATUS_CANCELLED)
        ->and($second->fresh()->status->value)->not->toBe('paid');
});

it('tells the app whether wallet verification is on through the reference payload', function (): void {
    $reference = fn () => $this->withToken($this->cashierToken)
        ->getJson("/api/v1/branches/{$this->branch->id}/reference")
        ->assertOk()->json('data');

    expect($reference()['wallet_verification'])->toBeFalse();

    $this->branch->update(['pos_settings' => ['wallet_verification_enabled' => true]]);

    expect($reference()['wallet_verification'])->toBeTrue();
});

it('safe deletes an ingredient through the API, demanding a note when a recipe uses it', function (): void {
    $gram = Unit::query()->where('symbol', 'g')->firstOrFail();
    $duplicate = Ingredient::factory()->create(['name' => 'Twice Imported Oil', 'unit_id' => $gram->id]);

    // Reason is part of every safe delete; an unknown code is refused.
    $this->withToken($this->ownerToken)
        ->deleteJson("/api/v1/ingredients/{$duplicate->id}", ['reason_code' => 'nonsense'])
        ->assertUnprocessable();

    $this->withToken($this->ownerToken)
        ->deleteJson("/api/v1/ingredients/{$duplicate->id}", ['reason_code' => 'duplicate_entry'])
        ->assertOk();

    expect($duplicate->fresh()->trashed())->toBeTrue();

    // A recipe user demands a note.
    $used = Ingredient::factory()->create(['name' => 'Recipe Salt', 'unit_id' => $gram->id]);
    $recipe = Recipe::query()->create(['menu_item_id' => $this->item->id]);
    $recipe->items()->create(['ingredient_id' => $used->id, 'qty' => 5]);

    $this->withToken($this->ownerToken)
        ->deleteJson("/api/v1/ingredients/{$used->id}", ['reason_code' => 'duplicate_entry'])
        ->assertUnprocessable();

    $this->withToken($this->ownerToken)
        ->deleteJson("/api/v1/ingredients/{$used->id}", ['reason_code' => 'duplicate_entry', 'note' => 'duplicate of the live salt'])
        ->assertOk();

    expect($used->fresh()->trashed())->toBeTrue()
        ->and(RecipeItem::query()->where('ingredient_id', $used->id)->count())->toBe(0)
        ->and($recipe->fresh())->not->toBeNull();
});

it('logs menu-item wastage in portions through the API, deducting the recipe from stock', function (): void {
    $gram = Unit::query()->where('symbol', 'g')->firstOrFail();
    $flour = Ingredient::factory()->create(['name' => 'Flour', 'unit_id' => $gram->id]);

    DB::table('ingredient_stocks')->insert([
        'branch_id' => $this->branch->id,
        'ingredient_id' => $flour->id,
        'stock_qty' => 1000,
        'avg_cost' => 0.5,
    ]);

    $recipe = Recipe::query()->create(['menu_item_id' => $this->item->id]);
    $recipe->items()->create(['ingredient_id' => $flour->id, 'qty' => 100]);

    $response = $this->withToken($this->managerToken)
        ->postJson("/api/v1/branches/{$this->branch->id}/inventory/wastage", [
            'menu_item_id' => $this->item->id,
            'qty' => 2,
            'reason_code' => 'spoiled',
            'note' => 'burned batch',
        ])
        ->assertCreated()->json();

    expect($response['message'])->toContain($this->item->name)
        ->and((float) DB::table('ingredient_stocks')->where('ingredient_id', $flour->id)->value('stock_qty'))->toBe(800.0);

    // An item with no recipe cannot leave stock.
    $orphan = MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'menu_category_id' => $this->item->menu_category_id,
    ]);

    $this->withToken($this->managerToken)
        ->postJson("/api/v1/branches/{$this->branch->id}/inventory/wastage", [
            'menu_item_id' => $orphan->id,
            'qty' => 1,
            'reason_code' => 'other',
        ])->assertUnprocessable();
});
