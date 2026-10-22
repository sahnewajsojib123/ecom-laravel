<?php
namespace App\Http\Controllers;
use App\Models\{Outlet,Category,Product,Inventory,Banner,Setting,Order};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Validation\Rule;
class AdminController extends Controller {
 public function login(Request $r){$d=$r->validate(['email'=>'required|email','password'=>'required|string']);if(!Auth::attempt($d+['is_admin'=>true]))return response()->json(['message'=>'Invalid administrator credentials.'],422);$r->session()->regenerate();return response()->json(['name'=>$r->user()->name]);}
 public function logout(Request $r){Auth::logout();$r->session()->invalidate();$r->session()->regenerateToken();return response()->json(['ok'=>true]);}
 public function data(){return ['user'=>Auth::user()->only('name','email'),'products'=>Product::with('category')->latest()->get(),'outlets'=>Outlet::all(),'categories'=>Category::all(),'inventories'=>Inventory::with('product','outlet')->get(),'banners'=>Banner::all(),'settings'=>Setting::pluck('value','key'),'orders'=>Order::with('items','outlet')->latest()->get()];}
 public function save(Request $r,string $type,?int $id=null){
 $models=['products'=>Product::class,'outlets'=>Outlet::class,'categories'=>Category::class,'inventories'=>Inventory::class,'banners'=>Banner::class];abort_unless(isset($models[$type]),404);
 $rules=match($type){
 'products'=>['name'=>'required|string|max:200','sku'=>['required','string','max:80',Rule::unique('products')->ignore($id)],'brand'=>'required|string|max:80','category_id'=>'required|exists:categories,id','description'=>'required|string|max:10000','image'=>'required|url:http,https|max:2000','featured'=>'required|boolean','active'=>'required|boolean'],
 'outlets'=>['name'=>'required|string|max:100','address'=>'required|string|max:255','phone'=>'required|string|max:40','active'=>'required|boolean'],
 'categories'=>['name'=>['required','string','max:100',Rule::unique('categories')->ignore($id)],'icon'=>'required|in:smartphone,laptop,tablet,headphones,watch,plug,monitor,gamepad'],
 'inventories'=>['product_id'=>['required','exists:products,id',Rule::unique('inventories')->where(fn($q)=>$q->where('outlet_id',$r->input('outlet_id')))->ignore($id)],'outlet_id'=>'required|exists:outlets,id','price'=>'required|integer|min:1|max:100000000','compare_price'=>'nullable|integer|gte:price|max:100000000','quantity'=>'required|integer|min:0|max:1000000'],
 'banners'=>['eyebrow'=>'required|string|max:100','title'=>'required|string|max:150','subtitle'=>'required|string|max:250','button_text'=>'required|string|max:60','product_id'=>'nullable|exists:products,id','background'=>['required','regex:/^#[a-fA-F0-9]{6}$/'],'active'=>'required|boolean']};
 $d=$r->validate($rules);$model=$models[$type];return DB::transaction(function()use($model,$id,$d,$type){
 if($type==='inventories')Outlet::whereKey($d['outlet_id'])->lockForUpdate()->firstOrFail();
 $item=$id?$model::whereKey($id)->lockForUpdate()->firstOrFail():new $model;
 if($type==='inventories'&&$id)abort_unless($item->product_id==(int)$d['product_id']&&$item->outlet_id==(int)$d['outlet_id'],422,'Existing inventory belongs to its product and outlet. Create a new record for a different assignment.');
 $item->fill($d)->save();return $item;
 },3);
 }
 public function settings(Request $r){$d=$r->validate(['store_name'=>'required|string|max:80','tagline'=>'required|string|max:150','phone'=>'required|string|max:40','announcement'=>'required|string|max:200','delivery_fee'=>'required|integer|min:0|max:10000','audio_title'=>'sometimes|required|string|max:120','audio_label'=>'sometimes|required|string|max:80','audio_image'=>'sometimes|required|url:http,https|max:2000','outlet_title'=>'sometimes|required|string|max:120','bottom_title'=>'sometimes|required|string|max:150','bottom_description'=>'sometimes|required|string|max:250','logo_caption'=>'sometimes|required|string|max:80']);foreach($d as $k=>$v)Setting::updateOrCreate(['key'=>$k],['value'=>(string)$v]);return ['ok'=>true];}
 public function status(Request $r,Order $order){$d=$r->validate(['status'=>'required|in:confirmed,processing,completed,cancelled']);return DB::transaction(function()use($order,$d){
 Outlet::whereKey($order->outlet_id)->lockForUpdate()->firstOrFail();$o=Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
 $allowed=['pending'=>['confirmed','cancelled'],'confirmed'=>['processing','cancelled'],'processing'=>['completed','cancelled'],'completed'=>[],'cancelled'=>[]];
 abort_unless(in_array($d['status'],$allowed[$o->status]??[]),422,'This order status transition is not allowed.');
 if($d['status']==='cancelled')foreach($o->items()->orderBy('product_id')->get() as $i)Inventory::where('outlet_id',$o->outlet_id)->where('product_id',$i->product_id)->increment('quantity',$i->quantity);
 $o->update($d);return $o;
 },3);}
}
