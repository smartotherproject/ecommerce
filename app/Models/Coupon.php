<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value'=>'decimal:2',
            'category_id'=>'integer',
            'minimum_order'=>'decimal:2',
            'maximum_discount'=>'decimal:2',
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
}
