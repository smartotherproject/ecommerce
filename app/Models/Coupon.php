<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $guarded = ['id'];

    protected $appends = ['applies_to'];

    protected function casts(): array
    {
        return [
            'value'=>'decimal:2',
            'minimum_order'=>'decimal:2',
            'maximum_discount'=>'decimal:2',
            'category_ids'=>'array',
            'addon_groups'=>'array',
            'usage_limit'=>'integer',
            'used_count'=>'integer',
            'starts_at'=>'datetime',
            'expires_at'=>'datetime',
            'is_active'=>'boolean',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function categoryNames(): array
    {
        if (empty($this->category_ids)) {
            return [];
        }

        return Category::whereIn('id', $this->category_ids)->orderBy('name')->pluck('name')->all();
    }

    /** Short human text, e.g. "Warranty add-on · Laptops". */
    public function getAppliesToAttribute(): string
    {
        $parts = [];
        if (! empty($this->addon_groups)) {
            $parts[] = implode(', ', $this->addon_groups).' add-on';
        }
        if (! empty($this->category_ids)) {
            $parts[] = implode(', ', $this->categoryNames());
        }

        return $parts ? implode(' · ', $parts) : 'Whole order';
    }
}
