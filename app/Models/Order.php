<?php

namespace App\Models;

use Carbon\Carbon;
use App\Traits\BelongsToUser;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;
    use BelongsToUser;

    protected $fillable = [
        'user_id',
        'subtotal',
        'shipping_cost',
        'subcharge',
        'total',
        'reference',
        'payment_status',
        'order_status',
        'payment_channel',
        'coupon_code',
        'order_no'
    ];

    protected $hidden = [
        'created_at',
    ];

    protected $with = ['subOrders'];
    
    protected function updatedAt(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Carbon::parse($value)->toFormattedDateString(),
            set: fn ($value) => $value,
        );
    }

    public function subOrders()
    {
        return $this->hasMany(SubOrder::class);
    }

}
