<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicketCategory extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** Default categories seeded on install. */
    public static function defaults(): array
    {
        return [
            'مشکل خرید',
            'مشکل پرداخت',
            'مشکل اتصال',
            'تمدید سرویس',
            'حجم یا زمان اضافه',
            'کیف پول',
            'تایید شماره موبایل',
            'سایر موارد',
        ];
    }
}
