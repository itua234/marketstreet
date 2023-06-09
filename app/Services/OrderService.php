<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Util\{
    CustomResponse, 
    Paystack, 
    Flutterwave, 
    Helper
};
use App\Http\Resources\{
    ProductResource,
    SalesResource,
    PurchaseResource
};
use App\Http\Requests\{
    CreateOrder, 
    CreateInvoice
};
use Illuminate\Support\Facades\{
    DB,
    Mail,
    Validator
};
use App\Models\{
    User, 
    Product,
    Order, 
    SubOrder,
    Address,
    OrderContent
};
use App\Events\{
    InvoiceSent
};

class OrderService
{  
    public function order(CreateOrder $request)
    {
        $user = auth()->user();
        $total = $request['total'];
        $reference = Helper::generateReference($user->id);
        $channel = strtoupper($request['payment_channel']);
        $url = $this->generatePaymentUrl($user, $channel, $total, $reference);
        $orderNo = mt_rand(1000, 9999);
        
        DB::transaction(
            function() use (
                $request, 
                $user,
                $orderNo,
                $total, 
                $reference, 
                $channel, 
            ){
            $order = Order::create([
                'user_id' => $user->id,
                'order_no' => $orderNo,
                'subtotal' => $request['subtotal'],
                'shipping_cost' => $request['shipping_cost'],
                'subcharge' => $request['subcharge'],
                'total' => $total,
                'reference' => $reference,
                'payment_channel' => $channel,
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
        });
       
        return CustomResponse::success("Payment Link:", $url);
    }

    public function generatePaymentUrl($user, $channel, $total, $reference)
    {
        if($channel === "PAYSTACK"):
            $payment = new Paystack;
            $response = $payment->initiateDeposit(
                $user->email, $total, $reference
            );

            return $response['data']["authorization_url"];
        elseif($channel === "FLUTTERWAVE"):
            $payment = new Flutterwave;
            $response = $payment->initializePayment(
                $user,
                [
                    'tx_ref' => $reference,
                    'amount' => $total,
                ]
            );

            return $response['data']["link"];
        endif;
    }

    public function listBuyerOrders($id)
    {
        $user = User::find($id);
        $orders = $user->orders;
        $items = [];
        foreach($orders as $order):
            $subOrders = $order->subOrders;
            foreach($subOrders as $subOrder):
                $orderNo = $subOrder->order_no;
                $contents = $subOrder->contents;
                foreach($contents as $content):
                    $status = $content->status;
                    $product = $content->product;
                    $item = [
                        'id' => $content->id,
                        'product_name' => $product->name,
                        'status' => $status,
                        'order_number'=> $orderNo,
                        'amount' => $content->price,
                        'brand_name' => $product->brand,
                        'images' => $product->images
                    ];
                    array_push($items, (object) $item);
                endforeach;
            endforeach;
           //  $order->items = $items;
        endforeach;
        //$orders = PurchaseResource::collection($orders);
        return CustomResponse::success("Orders:", $items);
    }

    public function listSellerOrders($id)
    {
        $user = User::find($id);
        $subOrders = $user->subOrders;
        $items = [];
        foreach($subOrders as $subOrder):
            $orderNo = $subOrder->order_no;
            $contents = $subOrder->contents;
            foreach($contents as $content):
                $status = $content->status;
                $product = $content->product;
                    $item = [
                        'id' => $content->id,
                        'product_name' => $product->name,
                        'payment' => $content->payment,
                        'status' => $status,
                        'order_number'=> $orderNo,
                        'amount' => $content->price,
                        'brand_name' => $product->brand,
                        'images' => $product->images
                    ];
                    array_push($items, (object) $item);
            endforeach;
            //$subOrder->contents = $items;
        endforeach;
        //$subOrders = SalesResource::collection($subOrders);
        return CustomResponse::success("Orders:", $items);
    }

    public function fetchBuyerOrderData($orderId)
    {
        $content = OrderContent::find($orderId);
        $product = $content->product;
        $subOrder = $content->subOrder;
        $items = [];
        $item = [
            'id' => $content->id,
            'product_name' => $product->name,
            'status' => $content->status,
            'order_number'=> $subOrder->order_no,
            'amount' => $content->price,
            'brand_name' => $product->brand,
            'images' => $product->images
        ];
        array_push($items, (object) $item);
        if(!$content) return CustomResponse::error('No order found', 404);

        return CustomResponse::success("Order Details:", $items);
    }

    public function fetchSellerOrderData($orderId)
    {
        $content = OrderContent::find($orderId);
        $product = $content->product;
        $subOrder = $content->subOrder;
        $items = [];
        $item = [
            'id' => $content->id,
            'product_name' => $product->name,
            'status' => $content->status,
            'order_number'=> $subOrder->order_no,
            'amount' => $content->price,
            'brand_name' => $product->brand,
            'images' => $product->images
        ];
        array_push($items, (object) $item);
        if(!$content) return CustomResponse::error('No order found', 404);

        return CustomResponse::success("Order Details:", $items);
    }

    public function sendInvoice(CreateInvoice $request)
    {
        $product = Product::find($request["cart"]["id"]);
        $price = $request["cart"]["price"];
        $quantity = isset($request["cart"]["quantity"]) ? $request["cart"]["quantity"] : 1;
        $buyer = User::find($request["id"]);
        $subcharge = 500;

        $subtotal = $request["cart"]["price"] * $request["cart"]["quantity"];
        $total = 0;
        $total += $product->shipping_cost;
        $total += $subcharge;
        $total += $subtotal;
        $reference = Helper::generateReference($buyer->id);
        $orderNo = mt_rand(1000, 9999);
        DB::transaction(
            function() use (
                $request, 
                &$order,
                $product, 
                $subtotal, 
                $total, 
                $reference, 
                $orderNo, 
                $buyer,
                $subcharge
            ){
            $order = Order::create([
                'user_id' => $buyer->id,
                'order_no' => $orderNo,
                'subtotal' => $subtotal,
                'shipping_cost' => $product->shipping_cost,
                'subcharge' => $subcharge,
                'total' => $total,
                'reference' => $reference,
            ]);
            $subOrder = SubOrder::create([
                'seller_id' => $product->seller_id,
                'order_id' => $order->id,
                'order_no' => $orderNo,
                'total' => $total,
            ]);
            $content = OrderContent::create([
                'sub_order_id' => $subOrder->id,
                'product_id' =>(int) $request["cart"]["id"],
                'quantity' =>(int) $request["cart"]["quantity"],
                'price' => $request["cart"]["price"]
            ]);
        });

        InvoiceSent::dispatch($order);
       
        return CustomResponse::success("An invoice has been sent to the buyer", $order->fresh());
    }

    public function invoicePayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'payment_channel' => 'required|string',
            'city' => 'required|string',
            'state' => 'required|string',
            'street' => 'required|string',
        ]);
        if($validator->fails()):
            return response([
                'message' => $validator->errors()->first(),
                'error' => $validator->getMessageBag()->toArray()
            ], 422);
        endif;

        $order = Order::find($request['id']);
        $user = User::find($order->user_id);
        $channel = strtoupper($request['payment_channel']);
        $order->payment_channel = $channel;
        $order->save();
        Address::create([
            'order_id' => $order->id,
            'city' => $request['city'],
            'state' => $request['state'],
            'street' => $request['street']
        ]);
        $total = $order->total;
        $reference = $order->reference;
        $url = $this->generatePaymentUrl($user, $channel, $total, $reference);

        return CustomResponse::success("Payment Link:", $url);
    }

    public function fetchCouponData($code)
    {
        $validator = Validator::make([
            'code' => $code,
        ], [
            'code' => 'required|string',
        ]);
        if($validator->fails()):
            return response([
                'message' => $validator->errors()->first(),
                'error' => $validator->getMessageBag()->toArray()
            ], 422);
        endif;

        $coupon = DB::table('coupons')
        ->where([
            'code' => $code
        ])->first();
        if(!$coupon):
            return CustomResponse::error("Invalid Coupon Code", 404);
        endif;
        return CustomResponse::success("Coupon:", $coupon);
    }

}