<?php 

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{
    DB, 
    Http, 
    Crypt, 
    Hash,
    Validator, 
    Mail
};
use App\Util\{
    CustomResponse,
};
use App\Http\Resources\{
    ProductResource
};
use App\Http\Requests\{
    CreateProduct, 
    ReviewProduct
};
use App\Models\{
    User, 
    Product, 
    Category, 
    Order, 
    Review, 
    ProductImage,
    Wishlist,
    Dropship
};


class ProductService
{
    public function show($id)
    {
        $validator = Validator::make([
            'id' => $id
        ], [
            'id' => 'required|integer',
        ]);
        if($validator->fails()):
            return response([
                'message' => $validator->errors()->first(),
                'error' => $validator->getMessageBag()->toArray()
            ], 422);
        endif;

        $product = Product::find($id);
        if(!$product) return CustomResponse::error('Product not found', 404);

        $message = "Product Details:";
        $product = new ProductResource($product);
        return CustomResponse::success($message, $product);
    }

    public function store(CreateProduct $request)
    {
        $product = Product::create([
            'seller_id' => auth()->user()->id,
            'category_id' => $request['category_id'],
            'name' => $request['name'],
            'brand' => $request['brand'],
            'stock' => isset($request['quantity']) ? $request['quantity'] : NULL,
            'price' => $request['price'],
            'description' => $request['description'],
            'shipping_cost' => $request['shipping_cost'],
            'is_negotiable' => $request['is_negotiable']
        ]);
        
        if($request->hasFile('image')):
            $image = $request->file('image');
            foreach($image as $photo):
                $response = \Cloudinary\Uploader::upload($photo);
                $url = $response["url"];
                ProductImage::create([
                    'url' => $url,
                    'product_id' => $product->id
                ]);
            endforeach;
        endif;

        $message = "Product has been created";
        $product = Product::without('reviews', 'owner')->where([
            'id' => $product->id
        ])->first();
        return CustomResponse::success($message, $product);
    }

    public function dropship(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'percent' => 'required|numeric',
        ]);
        if($validator->fails()):
            return response([
                'message' => $validator->errors()->first(),
                'error' => $validator->getMessageBag()->toArray()
            ], 422);
        endif;

        $product = Product::find($id);
        if(!$product) return CustomResponse::error('Product not found', 404);
        $percent = $request['percent'];
        $price = ($percent / 100) * ($product->price);
        $price += $product->price;
        
        $dropship = $product->replicate()->fill([
            'seller_id' => auth()->user()->id,
            'price' => $price,
            'is_negotiable' => 0,
            'is_dropshipped' => 1
        ]);
        $dropship->save();
        $images = $product->images()->pluck('url');
        foreach($images as $url):
            ProductImage::create([
                'url' => $url,
                'product_id' => $dropship->id
            ]);
        endforeach;

        $product->has_been_dropshipped = 1;
        $product->save();
        Dropship::create([
            'original_product_id' => $product->id,
            'dropship_product_id' => $dropship->id
        ]);
        $message = "Product has been dropshipped";
        $dropship = Product::without('reviews', 'owner')->where([
            'id' => $dropship->id
        ])->first();
        return CustomResponse::success($message, $dropship);
    }

    public function update(CreateProduct $request, $id)
    {
        $product = Product::find($id);
        if(!$product) return CustomResponse::error('Product not found', 404);

        $product->update([
            'category_id' => $request['category_id'],
            'name' => $request['name'],
            'brand' => $request['brand'],
            'stock' => isset($request['quantity']) ? $request['quantity'] : NULL,
            'price' => $request['price'],
            'description' => $request['description'],
            'shipping_cost' => $request['shipping_cost'],
            'is_negotiable' => $request['is_negotiable']
        ]);

        $images = $product->images()->pluck('url');
        foreach($images as $image):
            $parts = explode('/', $image);
            $count = count($parts);
            $publicId = explode('.', $parts[$count - 1]);
            $response = \Cloudinary\Uploader::destroy($publicId[0]);
        endforeach;
        $product->images()->delete();

        if($request->hasFile('image')):
            $image = $request->file('image');
            foreach($image as $photo):
                $response = \Cloudinary\Uploader::upload($photo);
                $url = $response["url"];
                $product->images()->create([
                    'url' => $url
                ]);
            endforeach;
        endif;

        $message = "Product has been updated";
        return CustomResponse::success($message, $product->fresh());
    }

    public function destroy($id)
    {
        $validator = Validator::make([
            'id' => $id
        ],[
            'id' => 'required|integer',
        ]);
        if($validator->fails()):
            return response([
                'message' => $validator->errors()->first(),
                'error' => $validator->getMessageBag()->toArray()
            ], 422);
        endif;

        $product = Product::find($id);
        if(!$product) return CustomResponse::error('Product not found', 404);

        $dropship = Dropship::where('original_product_id', $id)->first();
        if($dropship):
            $dropship->delete();
            $product_dropship = Product::find($dropship->dropship_product_id);
            $product_dropship->delete();
        endif;

        $wishlist = Wishlist::where('product_id', $id)->get();
        if($wishlist):
            foreach($wishlist as $list):
                $list->delete();
            endforeach;
        endif;

        $product->delete();
        $message = "Product deleted";
        return CustomResponse::success($message, null);
    }

    public function getCategories()
    {
        $categories = Category::all();
        return CustomResponse::success("Categories:", $categories);
    }

    public function createCategories(Request $request)
    {
        $response = Http::acceptJson()
            //->withToken($this->secretKey)
                ->get('https://dummyjson.com/products/categories');
        $response = json_decode($response);
        foreach($response as $category){
            Category::create([
                'name' => $category,
            ]);
        }
        return 'categories has been created';

        return CustomResponse::success("Categories:", NULL);
    }

    public function review(ReviewProduct $request, $id)
    {
        $user = auth()->user();
        try{
            $review = Review::create([
                'user_id' => $user->id,
                'name' => $user->firstname.' '.$user->lastname,
                'product_id' => $id,
                'text' => $request['text'],
                'rating' => $request['rating']
            ]);

            $owner = Product::find($id)->owner;
            if($request['text']):
                $reviews = $owner->profile->reviews + 1;
                $owner->profile()->update([
                    'reviews' => $reviews
                ]);
            endif;

        }catch(\Exception $e){
            $message = $e->getMessage();
            return CustomResponse::error($message);
        }
        
        return CustomResponse::success("successful", $review);
    }

    public function getProducts($categoryId)
    {
        $validator = Validator::make([
            'categoryId' => $categoryId
        ], [
            'categoryId' => 'required|integer',
        ]);
        if($validator->fails()):
            return response([
                'message' => $validator->errors()->first(),
                'error' => $validator->getMessageBag()->toArray()
            ], 422);
        endif;

        $category = Category::find($categoryId);
        if(!$category) return CustomResponse::error('Category not found', 404);

        $products = $category->products;
        return CustomResponse::success("Products:", $products);
    }

    public function FetchAllStoreProducts()
    {
        $products = Product::with('category')
        ->without('reviews', 'owner')
        ->get();
        //->paginate($perPage = 15, ['*'], $pageName = 'page');
        return CustomResponse::success("Products:", $products);
    }

    public function getAllUserProducts($userId)
    {
        $validator = Validator::make([
            'userId' => $userId
        ], [
            'userId' => 'required|integer',
        ]);
        if($validator->fails()):
            return response([
                'message' => $validator->errors()->first(),
                'error' => $validator->getMessageBag()->toArray()
            ], 422);
        endif;

        $products = Product::with('category')
        ->without('reviews', 'owner')
        ->where([
            'seller_id' => $userId
        ])->get();
        return CustomResponse::success("Products:", $products);
    }

    public function Wishlist($id, $action)
    {
        $validator = Validator::make([
            'id' => $id,
            'action' => $action
        ], [
            'id' => 'required|integer',
            'action' => 'required|string',
        ]);
        if($validator->fails()):
            return response([
                'message' => $validator->errors()->first(),
                'error' => $validator->getMessageBag()->toArray()
            ], 422);
        endif;

        $user = auth()->user();
        $product = Product::find($id);
        if(!$product) return CustomResponse::error('Product not found', 404);

        $check = Wishlist::where([
            'user_id' => $user->id,
            'product_id' =>(int) $id
        ])->first();

        if(is_null($check)):
            $wishlist = Wishlist::create([
                'user_id' => $user->id,
                'product_id' => $id,
                'name' => $product->name,
                'brand' => $product->brand,
                'price' => $product->price,
                'image' => $product->images
            ]);
            $message = "Product has been added to wishlist";
            $data = $wishlist;
        else:
            if($action === 'add'):
                $data = null;
                $message = "Product has already been added to wishlist";
            elseif($action === 'remove'):
                $data = null;
                $message = "Product has been removed from wishlist";
                $check->delete();
            endif;
        endif;
        
        return CustomResponse::success($message, $data);
    }

    public function getWishlist()
    {
        $user = auth()->user();
        $wishlists = $user->wishlists;
        return CustomResponse::success("Wishlists:", $wishlists);
    }

    public function FetchProductsByPrice($min, $max)
    {
        $validator = Validator::make([
            'min' => $min,
            'max' => $max
        ], [
            'min' => 'required|integer',
            'max' => 'required|integer',
        ]);
        if($validator->fails()):
            return response([
                'message' => $validator->errors()->first(),
                'error' => $validator->getMessageBag()->toArray()
            ], 422);
        endif;

        $min = (int) $min;
        $max = (int) $max;
    
        $products = Product::without('reviews', 'owner')
        ->with('category')
        ->whereBetween('price', [$min, $max])
        ->get();

        return CustomResponse::success("Products:", $products);
    }

    public function fetchTrendingProducts()
    {
        
    }

    public function searchProducts($query)
    {
        $products = Product::where('name', 'LIKE', '%'.$query.'%')
        ->with('category')
        ->without('reviews', 'owner')
        ->get();
        /*$category = Category::where('name', 'LIKE', '%'.$query.'%')->get();
        if(!$category):
            echo 'soa';
        else:
            echo 'poi';
        endif;*/
        return CustomResponse::success("Products:", $products);
    }
    
    public function getRandomProducts()
    {
        $response = Http::acceptJson()
            //->withToken($this->secretKey)
                ->get('https://dummyjson.com/products/?limit=50');
        $response = json_decode($response);
        return $response->products;
    }

    public function getProductDetails($product, $type)
    {
        $_product =  array_rand($product, 1);
        $shipping = mt_rand(200, 500);

        if($type == 'name'):
            return $product[$_product]->title;
        elseif($type == 'brand'):
            return $product[$_product]->brand;
        elseif($type == 'stock'):
            return $product[$_product]->stock;
        elseif($type == 'price'):
            return $product[$_product]->price;
        elseif($type == 'description'):
            return $product[$_product]->description;
        elseif($type == 'shipping'):
            return $shipping;
        elseif($type == 'category'):
            $category = Category::where('name', $product[$_product]->category)->first();
            return $category->id;
        elseif($type == 'photo'):
            return $product[$_product]->images;
        endif;
    }

    public function createProducts(Request $request)
    {
        $products = $this->getRandomProducts();
        return $products;
        /*$name = $this->getProductDetails($products,'name');
        $brand = $this->getProductDetails($products, 'brand');
        $stock = $this->getProductDetails($products,'stock');
        $price = $this->getProductDetails($products,'price');
        $description = $this->getProductDetails($products,'description');
        $shipping = $this->getProductDetails($products,'shipping');
        $category = $this->getProductDetails($products,'category');
        $images = $this->getProductDetails($products,'photo');*/
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

            $product = Product::create([
                'seller_id' => auth()->user()->id,
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

        $message = count($products)." products has been created";
        return CustomResponse::success($message, null);
    }

}