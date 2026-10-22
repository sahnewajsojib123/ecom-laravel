<?php
namespace App\Http\Controllers;
use App\Models\{Outlet,Category,Product,Inventory,Banner,Setting,Order};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class StoreController extends Controller {
 public function bootstrap(){return response()->json(['outlets'=>Outlet::where('active',true)->get(),'categories'=>Category::all(),'banners'=>Banner::where('active',true)->with('product')->get(),'settings'=>Setting::pluck('value','key')]);}
 public function products(Request $r){
 $d=$r->validate(['outlet_id'=>'required|integer|exists:outlets,id','q'=>'nullable|string|max:100','category_id'=>'nullable|integer','sort'=>'nullable|in:featured,price_asc,price_desc','featured'=>'nullable|boolean']);
 abort_unless(Outlet::where('id',$d['outlet_id'])->where('active',true)->exists(),404);
 $q=Inventory::with('product.category')->where('outlet_id',$d['outlet_id'])->whereHas('product',function($q)use($d){$q->where('active',true);if(!empty($d['q']))$q->where(fn($s)=>$s->where('name','like','%'.$d['q'].'%')->orWhere('brand','like','%'.$d['q'].'%'));if(!empty($d['category_id']))$q->where('category_id',$d['category_id']);if(!empty($d['featured']))$q->where('featured',true);});
 match($d['sort']??'featured'){'price_asc'=>$q->orderBy('price'),'price_desc'=>$q->orderByDesc('price'),default=>$q->orderByDesc(Product::select('featured')->whereColumn('products.id','inventories.product_id'))->orderBy('id')};
 return $q->paginate(24);
 }
 public function product(Product $product){abort_unless($product->active,404);return $product->load(['category','inventories'=>fn($q)=>$q->whereHas('outlet',fn($o)=>$o->where('active',true))->with('outlet')]);}
 public function order(Request $r){
 $d=$r->validate(['idempotency_key'=>'required|uuid','outlet_id'=>'required|integer|exists:outlets,id','customer_name'=>'required|string|max:100','phone'=>['required','regex:/^\+?[0-9 ()-]{8,20}$/'],'email'=>'nullable|email|max:200','address'=>'required|string|max:1000','fulfillment'=>'required|in:delivery,pickup','items'=>'required|array|min:1|max:50','items.*.product_id'=>'required|integer|distinct|exists:products,id','items.*.quantity'=>'required|integer|min:1|max:20']);
 abort_unless(Outlet::where('id',$d['outlet_id'])->where('active',true)->exists(),422,'Outlet unavailable.');
 return DB::transaction(function()use($d){
 // Lock the outlet first: serializes inventory reservations and idempotent retries for this outlet.
 Outlet::whereKey($d['outlet_id'])->lockForUpdate()->firstOrFail();
 if($existing=Order::where('idempotency_key',$d['idempotency_key'])->first())return response()->json(['reference'=>$existing->reference,'total'=>$existing->total],200);
 $items=[];$subtotal=0;
 foreach(collect($d['items'])->sortBy('product_id') as $line){$stock=Inventory::with('product')->where('outlet_id',$d['outlet_id'])->where('product_id',$line['product_id'])->lockForUpdate()->first();
 if(!$stock||!$stock->product->active||$stock->quantity<$line['quantity'])throw ValidationException::withMessages(['items'=>'A product is unavailable or has insufficient stock. Refresh your cart.']);
 $items[]=['product_id'=>$stock->product_id,'name'=>$stock->product->name,'quantity'=>$line['quantity'],'unit_price'=>$stock->price];$subtotal+=$stock->price*$line['quantity'];$stock->decrement('quantity',$line['quantity']);}
 $fee=$d['fulfillment']==='pickup'?0:(int)(Setting::where('key','delivery_fee')->value('value')??120);
 $order=Order::create(collect($d)->except('items')->all()+['reference'=>(string)Str::uuid(),'subtotal'=>$subtotal,'delivery_fee'=>$fee,'total'=>$subtotal+$fee]);$order->items()->createMany($items);
 return response()->json(['reference'=>$order->reference,'total'=>$order->total],201);
 },3);
 }
}
