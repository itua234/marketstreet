<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use App\Models\{
    User, 
    Product, 
    Category, 
    ProductImage,
};

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $response = Http::acceptJson()
            ->get('https://dummyjson.com/products/?limit=50');
        $response = json_decode($response);
        $products = $response->products;
        //return $products;
        foreach($products as $product):
            $name = $product->title;
            $brand = $product->brand;
            $stock = $product->stock;
            $price = $product->price;
            $description = $product->description;
            $shipping = mt_rand(200, 500);
            $images = $product->images;
            $category = Category::where('name', $product->category)->first();
            $is_negotiable = mt_rand(0,1);

            $user = User::inRandomOrder()->first();
            $product = Product::create([
                'seller_id' => $user->id,
                'category_id' => $category->id,
                'name' => $name,
                'brand' => $brand,
                'stock' => $stock,
                'price' => $price,
                'description' => $description,
                'shipping_cost' => $shipping,
                'is_negotiable' => $is_negotiable
            ]);
            
            foreach($images as $photo):
                ProductImage::create([
                    'url' => $photo,
                    'product_id' => $product->id
                ]);
            endforeach;
        endforeach;
    }
}
