<?php

declare(strict_types=1);

use App\Actions\Sales\CreateOrderAction;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\PosSettings;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed([ChartOfAccountsSeeder::class, RoleSeeder::class]);

    $this->branch = Branch::factory()->create(['code' => 'BR01']);
    $this->other = Branch::factory()->create(['code' => 'BR02']);
    $this->owner = User::factory()->role('owner')->create();

    $category = MenuCategory::factory()->create(['branch_id' => $this->branch->id]);
    $this->item = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $category->id, 'base_price' => '200.00']);
    $otherCategory = MenuCategory::factory()->create(['branch_id' => $this->other->id]);
    $this->otherItem = MenuItem::factory()->create(['branch_id' => $this->other->id, 'menu_category_id' => $otherCategory->id, 'base_price' => '200.00']);

    Setting::current()->update(['tax_registration_no' => '000111222-0101']);
});

function invoiceFor(Branch $branch, MenuItem $item): string
{
    $order = app(CreateOrderAction::class)->execute(
        branch: $branch,
        type: OrderType::Takeaway,
        lines: [['menu_item_id' => $item->id, 'quantity' => 1]],
    );

    return test()->actingAs(test()->owner, 'staff')->get(route('admin.orders.receipt', $order))->assertOk()->getContent();
}

function saveInvoicePrint(object $test, Branch $branch, array $extra): void
{
    $test->actingAs($test->owner, 'staff')
        ->withSession(['branch_id' => $branch->id])
        ->put(route('admin.settings.invoice-print'), ['layout' => 'classic', 'paper' => '80mm', ...$extra])
        ->assertSessionHasNoErrors();
}

it('keeps invoices as they were until the branch switches Mushak on', function (): void {
    $html = invoiceFor($this->branch, $this->item);

    expect($html)->not->toContain('Mushak-6.3')
        ->and($html)->not->toContain('Challan No')
        ->and($html)->toContain('BIN: 000111222-0101');
});

it('prints the Mushak-6.3 tax invoice heading, BIN and challan number once a branch switches it on', function (): void {
    $this->branch->update(['pos_settings' => [...($this->branch->pos_settings ?? []), 'invoice_mushak' => true, 'mushak_bin' => '555666777-0202']]);

    $html = invoiceFor($this->branch, $this->item);

    expect($html)->toContain('Mushak-6.3')
        ->and($html)->toContain('Tax Invoice')
        ->and($html)->toContain('BIN: 555666777-0202')
        ->and($html)->toContain('Challan No: '.$this->branch->code)
        ->and($html)->not->toContain('000111222-0101');
});

it('falls back to the business BIN when the branch has none of its own', function (): void {
    $this->branch->update(['pos_settings' => [...($this->branch->pos_settings ?? []), 'invoice_mushak' => true]]);

    expect(invoiceFor($this->branch, $this->item))->toContain('Mushak-6.3')->toContain('BIN: 000111222-0101');
});

it('applies the Mushak switch to one branch only', function (): void {
    $this->branch->update(['pos_settings' => [...($this->branch->pos_settings ?? []), 'invoice_mushak' => true]]);

    expect(invoiceFor($this->branch, $this->item))->toContain('Mushak-6.3')
        ->and(invoiceFor($this->other, $this->otherItem))->not->toContain('Mushak-6.3');
});

it('saves the Mushak switch and BIN from the invoice print settings', function (): void {
    saveInvoicePrint($this, $this->branch, ['show_mushak' => true, 'mushak_bin' => ' 123456789-0303 ']);

    $settings = PosSettings::for($this->branch->fresh());

    expect($settings['invoice_mushak'])->toBeTrue()
        ->and($settings['mushak_bin'])->toBe('123456789-0303')
        ->and(PosSettings::for($this->other->fresh())['invoice_mushak'])->toBeFalse();

    saveInvoicePrint($this, $this->branch, ['show_mushak' => false]);

    expect(PosSettings::for($this->branch->fresh())['invoice_mushak'])->toBeFalse()
        ->and(PosSettings::for($this->branch->fresh())['mushak_bin'])->toBe('123456789-0303');
});

it('prints the Mushak heading in Bangla', function (): void {
    $this->branch->update(['pos_settings' => [...($this->branch->pos_settings ?? []), 'invoice_mushak' => true]]);
    $this->owner->update(['locale' => 'bn']);

    expect(invoiceFor($this->branch, $this->item))->toContain('মূসক-৬.৩')->toContain('কর চালানপত্র');
});

it('shows the Mushak heading in the settings preview as it is being set', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->withSession(['branch_id' => $this->branch->id])
        ->get(route('admin.settings.invoice-preview', ['layout' => 'classic', 'paper' => '80mm', 'show_mushak' => 1, 'mushak_bin' => '999888777-0404']))
        ->assertOk()
        ->assertSee('Mushak-6.3')
        ->assertSee('BIN: 999888777-0404');
});
