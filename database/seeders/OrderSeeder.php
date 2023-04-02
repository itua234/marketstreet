<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\{
    User, 
    Product, 
    Category, 
    Order,
};
use App\Util\Helper;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $product = Product::inRandomOrder()->first();
        $user = User::inRandomOrder()->first();
        $total = mt_rand(5000, 65000);
        $reference = Helper::generateReference($user->id);
        $channel = ['FLUTTERWAVE', 'PAYSTACK'];
        $orderNo = mt_rand(1000, 9999);

        $order = Order::create([
            'user_id' => $user->id,
            'order_no' => $orderNo,
            'subtotal' => $request['subtotal'],
            'shipping_cost' => mt_rand(200, 500),
            'subcharge' => $request['subcharge'],
            'total' => $total,
            'reference' => $reference,
            'payment_channel' => $channel[mt_rand(0,1)],
            'coupon_code' => isset($request['coupon_code']) ? $request['coupon_code'] : NULL
        ]);
        $array = [];
        foreach($request['cart'] as $item):
            $product = Product::find($item['id']);
            if($product->is_dropshipped):
                $actualId = $product->dropship()->pluck('original_product_id')->first();
                $product = Product::find($actualId);
                $itemId = $product->id;
                $price = $product->price;
                $total = $price * $item['quantity'];
            else:
                $itemId = $item['id'];
                $price = $item['price'];
                $total = $price * $item['quantity'];
            endif;

            if(in_array($product->seller_id, $array)):
                $sub = SubOrder::where([
                    'order_no' => $orderNo,
                    'seller_id' => $product->seller_id
                ])->first();
                $content = OrderContent::create([
                    'sub_order_id' => $sub->id,
                    'product_id' => (int) $itemId,
                    'quantity' =>(int) $item['quantity'],
                    'price' => $price
                ]);
                $sub->total += $total;
                $sub->save();
            else:
                $subOrder = SubOrder::create([
                    'seller_id' => $product->seller_id,
                    'order_id' => $order->id,
                    'order_no' => $orderNo,
                    'total' => $total,
                ]);
                $content = OrderContent::create([
                    'sub_order_id' => $subOrder->id,
                    'product_id' => (int) $itemId,
                    'quantity' =>(int) $item['quantity'],
                    'price' => $price
                ]);
            endif;
            
            array_push($array, $product->seller_id);
        endforeach;
        Address::create([
            'order_id' => $order->id,
            'city' => $request["address"]['city'],
            'state' => $request["address"]['state'],
            'street' => $request["address"]['street']
        ]);
    }
}
