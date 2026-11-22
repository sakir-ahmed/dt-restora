<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\FlushesPublicCache;
use App\Models\Concerns\HasMenuNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MenuItem extends Model
{
    use BelongsToBranch, HasFactory;
    use FlushesPublicCache;
    use HasMenuNumber;

    protected $fillable = [
        'branch_id', 'menu_category_id', 'name', 'name_bn',
        'description', 'description_bn', 'photo_path', 'barcode', 'aggregator_sku',
        'base_price', 'is_available', 'is_vegetarian', 'sort_order', 'pre_order_required', 'pre_order_lead_hours',
    ];

    protected $appends = ['cover_url', 'requires_pre_order', 'pre_order_lead'];

    protected $hidden = ['media'];

    protected $casts = [
        'base_price' => 'decimal:2',
        'is_available' => 'boolean',
        'is_vegetarian' => 'boolean',
        'sort_order' => 'integer',
        'pre_order_required' => 'boolean',
        'pre_order_lead_hours' => 'integer',
    ];

    /** @var array<int, array{required: bool, lead: ?int}> */
    private static array $categoryRules = [];

    /** @return MorphMany<Media, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Whether this dish must be ordered ahead: its own setting when it has one, else its category's.
     */
    protected function requiresPreOrder(): Attribute
    {
        return Attribute::get(fn (): bool => $this->pre_order_required ?? $this->categoryRule()['required']);
    }

    /**
     * Hours of notice a pre-order for this dish needs (0 when it only has to be for a later time).
     */
    protected function preOrderLead(): Attribute
    {
        return Attribute::get(fn (): int => (int) ($this->pre_order_lead_hours ?? $this->categoryRule()['lead'] ?? 0));
    }

    /**
     * @return array{required: bool, lead: ?int}
     */
    private function categoryRule(): array
    {
        $id = (int) $this->menu_category_id;

        return self::$categoryRules[$id] ??= (function () use ($id): array {
            $category = MenuCategory::query()->withoutGlobalScopes()->find($id, ['id', 'pre_order_required', 'pre_order_lead_hours']);

            return ['required' => (bool) $category?->pre_order_required, 'lead' => $category?->pre_order_lead_hours];
        })();
    }

    /**
     * Forget remembered category rules (after a category changes, and between tests).
     */
    public static function forgetCategoryRules(): void
    {
        self::$categoryRules = [];
    }

    /**
     * Public URL of the dish picture: the featured photo, else the first uploaded photo, else the
     * legacy photo path. Eager load `media` to avoid extra queries.
     */
    protected function coverUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->relationLoaded('media')) {
                $images = $this->media->filter(fn (Media $media): bool => $media->kind === Media::KIND_IMAGE && $media->is_active && $media->path !== null);
                $image = $images->first(fn (Media $media): bool => $media->is_featured) ?? $images->first();

                if ($image !== null) {
                    return $image->src();
                }
            }

            if (! $this->photo_path) {
                return null;
            }

            return str_starts_with($this->photo_path, 'http') ? $this->photo_path : '/storage/'.ltrim($this->photo_path, '/');
        });
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<MenuCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    /** @return HasMany<MenuItemVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(MenuItemVariant::class)->orderBy('sort_order');
    }

    /** @return HasMany<MenuItemAddOn, $this> */
    public function addOns(): HasMany
    {
        return $this->hasMany(MenuItemAddOn::class)->orderBy('sort_order');
    }

    /** @return HasOne<Recipe, $this> */
    /** @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function recipe()
    {
        return $this->hasOne(Recipe::class);
    }

    public function scopeAvailable(Builder $builder): Builder
    {
        return $builder->where('is_available', true);
    }

    public function scopeSearch(Builder $builder, ?string $term): Builder
    {
        if ($term === null || $term === '') {
            return $builder;
        }

        return $builder->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('name_bn', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%");
        });
    }

    public function displayName(string $locale): string
    {
        return $locale === 'bn' && $this->name_bn ? $this->name_bn : $this->name;
    }

    /**
     * Price of a variant, or the base price when the item has no variants.
     */
    public function priceFor(?MenuItemVariant $variant = null): string
    {
        if ($variant !== null && $variant->menu_item_id === $this->id) {
            return (string) $variant->price;
        }

        return (string) $this->base_price;
    }
}
