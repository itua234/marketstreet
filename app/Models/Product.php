<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'seller_id',
        'category_id',
        'name',
        'brand',
        'quantity',
        'price',
        'description',
        'sales',
        'shipping_cost',
        'is_negotiable'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    protected $with = ['reviews', 'images', 'owner'];

    public function owner()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function orders()
    {
        return $this->belongsToMany(Order::class, 'order_contents');
    }

}
