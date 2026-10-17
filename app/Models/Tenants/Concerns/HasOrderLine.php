<?php

namespace App\Models\Tenants\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Shared behaviour of an order line (CartItem and SellingDetail): a normalised per-item note
 * and the category name of the line's product.
 *
 * @property-read \App\Models\Tenants\Product|null $product
 */
trait HasOrderLine
{
    public const NOTE_MAX_LENGTH = 255;

    /**
     * Set-only. Control characters (which could inject printer commands) become spaces, whitespace is
     * collapsed, the value is trimmed and cut to 255 characters. Empty becomes null.
     */
    public function note(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): ?string => static::normalizeNote($value),
        );
    }

    public static function normalizeNote(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = mb_scrub((string) $value);
        $text = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = mb_substr(trim($text), 0, self::NOTE_MAX_LENGTH);
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * Category name of the product. Does not load anything itself: eager load `product.category` first.
     */
    public function categoryName(): ?string
    {
        return $this->product?->category?->name;
    }
}
