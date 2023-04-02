<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Category;
use Illuminate\Support\Facades\Http;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $response = Http::acceptJson()
            ->get('https://dummyjson.com/products/categories');
        $response = json_decode($response);
        foreach($response as $category){
            Category::create([
                'name' => $category,
                //'slug' => ,
                //'image' => ,
                //'description' => 
            ]);
        }
    }
}
