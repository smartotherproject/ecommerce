<?php

namespace App\Http\Controllers;

use App\Models\{Banner, Brand, Category, Contact, Coupon, Faq, Order, Page, Post, Product, QuoteRequest, Setting, Subscriber, Testimonial};
use App\Support\ProductAddons;
use App\Support\Shipping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreController extends Controller
{
    public function storefront(): JsonResponse
    {
        $posts=Post::published()->latest('published_at')->limit(6)->get();

        return response()->json([
            'settings'=>collect(Setting::flat())->except(['vapid_private_key','header_scripts','footer_scripts'])->all(),
            'categories'=>Category::where('is_active',true)->withCount(['products'=>fn($q)=>$q->active()])->orderBy('sort_order')->get(),
            'brands'=>Brand::where('is_active',true)->orderBy('name')->get(),
            'banners'=>Banner::where('is_active',true)->orderBy('sort_order')->get(),
            'featured_products'=>Product::active()->where('is_featured',true)->with('category','brand')->latest()->limit(12)->get(),
            'sale_products'=>Product::active()->where('is_sale',true)->whereNotNull('sale_price')->with('category','brand')->latest()->limit(12)->get(),
            'testimonials'=>Testimonial::where('is_active',true)->latest()->get(),
            'faqs'=>Faq::where('is_active',true)->orderBy('sort_order')->get(),
            'posts'=>$posts,
            'latest_posts'=>$posts,
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $data=$request->validate([
            'search'=>'nullable|string|max:200',
            'q'=>'nullable|string|max:200',
            'category'=>'nullable|string|max:100',
            'brand'=>'nullable|string|max:100',
            'sort'=>'nullable|in:newest,latest,price_asc,price_desc,name',
            'min_price'=>'nullable|numeric|min:0',
            'max_price'=>'nullable|numeric|min:0',
            'sale'=>'nullable|in:0,1',
            'per_page'=>'nullable|integer|min:1|max:48',
            'page'=>'nullable|integer|min:1',
        ]);
        $search=trim((string)($data['search']??$data['q']??''));
        $q=Product::active()->with('category','brand');
        if($search!=='') {
            $terms=preg_split('/\s+/u',$search,-1,PREG_SPLIT_NO_EMPTY) ?: [$search];
            $q->where(function($outer) use($terms) {
                foreach($terms as $term) {
                    $like='%'.addcslashes($term,'%_\\').'%';
                    $outer->where(function($inner) use($like) {
                        $inner->where('name','like',$like)->orWhere('sku','like',$like);
                    });
                }
            });
        }
        $q->when($data['category']??null, fn($q,$v)=>$q->whereHas('category',fn($x)=>$x->where('slug',$v)));
        $q->when($data['brand']??null, fn($q,$v)=>$q->whereHas('brand',fn($x)=>$x->where('slug',$v)));
        $effectivePrice='CASE WHEN is_sale = 1 AND sale_price IS NOT NULL THEN sale_price ELSE price END';
        if(array_key_exists('min_price',$data) && $data['min_price']!==null && $data['min_price']!=='') {
            $q->whereRaw("({$effectivePrice}) >= ?",[(float)$data['min_price']]);
        }
        if(array_key_exists('max_price',$data) && $data['max_price']!==null && $data['max_price']!=='') {
            $q->whereRaw("({$effectivePrice}) <= ?",[(float)$data['max_price']]);
        }
        $q->when(($data['sale']??null)==='1', fn($q)=>$q->where('is_sale',true)->whereNotNull('sale_price'));
        match($data['sort']??'newest') {
            'price_asc'=>$q->orderByRaw("{$effectivePrice} asc"),
            'price_desc'=>$q->orderByRaw("{$effectivePrice} desc"),
            'name'=>$q->orderBy('name'),
            default=>$q->latest(),
        };
        return response()->json($q->paginate($data['per_page']??12)->withQueryString());
    }

    public function searchSuggest(Request $request): JsonResponse
    {
        $data=$request->validate(['q'=>'required|string|min:1|max:200']);
        $search=trim($data['q']);
        $terms=preg_split('/\s+/u',$search,-1,PREG_SPLIT_NO_EMPTY) ?: [$search];
        $q=Product::active()->select(['id','name','slug','image','price','sale_price','is_sale']);
        $q->where(function($outer) use($terms) {
            foreach($terms as $term) {
                $like='%'.addcslashes($term,'%_\\').'%';
                $outer->where(function($inner) use($like) {
                    $inner->where('name','like',$like)->orWhere('sku','like',$like);
                });
            }
        });
        return response()->json(['data'=>$q->orderBy('name')->limit(8)->get()]);
    }

    public function product(string $slug): JsonResponse
    {
        $product=Product::active()->with('category','brand')->where('slug',$slug)->firstOrFail();
        $related=Product::active()->where('category_id',$product->category_id)->whereKeyNot($product->id)->limit(6)->get();
        return response()->json(['product'=>$product,'related'=>$related]);
    }

    public function posts(Request $request): JsonResponse { return response()->json(Post::published()->latest('published_at')->paginate(min($request->integer('per_page',9),30))); }
    public function post(string $slug): JsonResponse { return response()->json(['post'=>Post::published()->where('slug',$slug)->firstOrFail()]); }
    public function page(string $slug): JsonResponse { return response()->json(['page'=>Page::where('is_active',true)->where('slug',$slug)->firstOrFail()]); }

    public function coupon(Request $request): JsonResponse
    {
        $data=$request->validate([
            'coupon_code'=>'required|string|max:50',
            'items'=>'required|array|min:1|max:30',
            'items.*.product_id'=>'required|integer|exists:products,id',
            'items.*.quantity'=>'required|integer|min:1|max:20',
            'items.*.addons'=>'nullable|array',
            'items.*.addons.*'=>'nullable|string|max:80',
        ]);
        $products=Product::active()->whereIn('id',collect($data['items'])->pluck('product_id'))->get()->keyBy('id');
        $couponLines=[];
        $subtotalCents=collect($data['items'])->sum(function($item) use($products,&$couponLines) {
            $product=$products->get($item['product_id']);
            if(!$product) throw ValidationException::withMessages(['items'=>['A selected product is unavailable.']]);
            $resolved=ProductAddons::forProduct($product,$item['addons']??null);
            $base=$product->is_sale && $product->sale_price!==null ? $product->sale_price : $product->price;
            $price=round(((float)$base)+$resolved['extra'],2);
            $lineCents=(int) round($price*100)*$item['quantity'];
            $couponLines[]=['product'=>$product,'quantity'=>(int)$item['quantity'],'summary'=>$resolved['summary'],'total_cents'=>$lineCents];
            return $lineCents;
        });
        [$coupon,$discountCents]=$this->resolveCoupon($data['coupon_code'],$subtotalCents,false,$couponLines);

        return response()->json([
            'message'=>"Coupon {$coupon->code} applied.",
            'coupon'=>['code'=>$coupon->code,'type'=>$coupon->type,'value'=>$coupon->value],
            'subtotal'=>number_format($subtotalCents/100,2,'.',''),
            'discount'=>number_format($discountCents/100,2,'.',''),
            'total'=>number_format(($subtotalCents-$discountCents)/100,2,'.',''),
        ]);
    }

    public function order(Request $request): JsonResponse
    {
        $data=$request->validate([
            'customer_name'=>'required|string|min:2|max:120','email'=>'nullable|email|max:255',
            'phone'=>['required','string','max:30','regex:/^(?:\+92|0092|92|0)?3[0-9]{9}$/'],
            'city'=>['required','string','min:2','max:100',Rule::in(Shipping::CITIES)],
            'address'=>'required|string|min:10|max:1000','notes'=>'nullable|string|max:1000','coupon_code'=>'nullable|string|max:50',
            'items'=>'required|array|min:1|max:30','items.*.product_id'=>'required|integer|exists:products,id','items.*.quantity'=>'required|integer|min:1|max:20',
            'items.*.addons'=>'nullable|array','items.*.addons.*'=>'nullable|string|max:80',
        ]);
        $order=DB::transaction(function() use($data) {
            $lines=[]; $subtotalCents=0; $stockNeed=[];
            foreach($data['items'] as $item) {
                $pid=(int)$item['product_id'];
                $stockNeed[$pid]=($stockNeed[$pid]??0)+(int)$item['quantity'];
            }
            foreach($data['items'] as $item) {
                $product=Product::active()->lockForUpdate()->find($item['product_id']);
                if(!$product) throw ValidationException::withMessages(['items'=>["Product #{$item['product_id']} is unavailable."]]);
                if($product->stock < $stockNeed[$product->id]) throw ValidationException::withMessages(['items'=>["Insufficient stock for product #{$item['product_id']}."]]);
                $resolved=ProductAddons::forProduct($product,$item['addons']??null);
                $base=$product->is_sale && $product->sale_price!==null ? $product->sale_price : $product->price;
                $price=round(((float)$base)+$resolved['extra'],2);
                $unitCents=(int) round($price*100); $totalCents=$unitCents*$item['quantity']; $subtotalCents+=$totalCents;
                $total=number_format($totalCents/100,2,'.','');
                $lines[]=[$product,$item['quantity'],number_format($price,2,'.',''),$total,$resolved['addons'],$resolved['summary']];
            }
            foreach($stockNeed as $pid=>$qty) Product::whereKey($pid)->decrement('stock',$qty);
            $couponLines=array_map(fn($l)=>['product'=>$l[0],'quantity'=>(int)$l[1],'summary'=>$l[5],'total_cents'=>(int) round(((float)$l[3])*100)],$lines);
            [$coupon,$discountCents]=$this->resolveCoupon($data['coupon_code']??null,$subtotalCents,true,$couponLines);
            $city=Shipping::assertValidCity($data['city']);
            $shippingAmount=Shipping::chargeForCity($city);
            $shippingCents=(int) round($shippingAmount*100);
            $subtotal=number_format($subtotalCents/100,2,'.','');
            $shipping=number_format($shippingCents/100,2,'.','');
            $discount=number_format($discountCents/100,2,'.','');
            $total=number_format(($subtotalCents-$discountCents+$shippingCents)/100,2,'.','');
            $order=Order::create([...collect($data)->except(['items','coupon_code'])->all(),'city'=>$city,'order_number'=>'NX-'.now()->format('ymd').'-'.strtoupper(Str::random(7)),
                'payment_method'=>'cod','coupon_id'=>$coupon?->id,'coupon_code'=>$coupon?->code,'status'=>'pending','subtotal'=>$subtotal,'shipping'=>$shipping,'discount'=>$discount,'total'=>$total]);
            foreach($lines as [$p,$qty,$price,$total,$addons,$summary]) $order->items()->create([
                'product_id'=>$p->id,'name'=>$p->name,'sku'=>$p->sku,'quantity'=>$qty,'unit_price'=>$price,'total'=>$total,
                'options'=>$addons ? ['addons'=>$addons,'summary'=>$summary] : null,
            ]);
            if($coupon) $coupon->increment('used_count');
            $order->statusHistory()->create(['status'=>'pending','note'=>'Order placed']);
            return $order->load('items');
        },3);
        return response()->json(['message'=>'Order placed successfully.','order'=>$order],201);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data=$request->validate(['email'=>'required|email|max:255']);
        Subscriber::updateOrCreate(['email'=>strtolower($data['email'])],['is_active'=>true]);
        return response()->json(['message'=>'Subscription confirmed.'],201);
    }

    public function contact(Request $request): JsonResponse
    {
        Contact::create($request->validate(['name'=>'required|string|max:120','email'=>'nullable|email|max:255','phone'=>'nullable|string|max:30',
            'subject'=>'nullable|string|max:180','message'=>'required|string|min:10|max:5000']));
        return response()->json(['message'=>'Message received.'],201);
    }

    public function quoteRequest(Request $request): JsonResponse
    {
        $requirementTypes=[
            'New Laptops','Refurbished Laptops','Desktop Computers','Gaming Laptops','Workstations',
            'Networking Equipment','Managed IT Services','Annual Maintenance Contract (AMC)','Corporate IT Procurement','Other',
        ];
        $brands=['Dell','HP','Lenovo','Apple','ASUS','Acer','Cisco','MikroTik','Ubiquiti','TP-Link','Other'];
        $budgets=['Under PKR 50,000','PKR 50,000 – 100,000','PKR 100,000 – 200,000','PKR 200,000+','Prefer to Discuss'];
        $networking=['Routers','Managed Switches','Wireless Access Points','Network Racks','Structured Cabling','Patch Panels','Firewalls','VPN Configuration','Network Deployment','Network Maintenance','Other'];
        $services=['On-Site Support','Remote Support','Preventive Maintenance','IT Consultation','Network Setup','Server Installation','Security Solutions','Cloud Support','Annual Maintenance Contract (AMC)'];
        $contacts=['Phone Call','WhatsApp','Email'];
        $itTypes=['Managed IT Services','Annual Maintenance Contract (AMC)','Corporate IT Procurement'];

        $data=$request->validate([
            'company_name'=>'required|string|min:2|max:180',
            'contact_person'=>'required|string|min:2|max:120',
            'designation'=>'nullable|string|max:120',
            'email'=>'required|email|max:255',
            'phone'=>'required|string|max:40',
            'website'=>'nullable|string|max:255',
            'business_address'=>'nullable|string|max:2000',
            'requirement_type'=>['required','string',Rule::in($requirementTypes)],
            'preferred_brands'=>'nullable|array',
            'preferred_brands.*'=>['string',Rule::in($brands)],
            'quantity'=>'required|string|max:80',
            'preferred_specs'=>'nullable|string|max:5000',
            'budget_range'=>['nullable','string',Rule::in($budgets)],
            'networking_requirements'=>'nullable|array',
            'networking_requirements.*'=>['string',Rule::in($networking)],
            'service_requirements'=>'nullable|array',
            'service_requirements.*'=>['string',Rule::in($services)],
            'project_details'=>'nullable|string|max:10000',
            'delivery_city'=>'nullable|string|max:100',
            'expected_delivery_date'=>'nullable|date',
            'preferred_contact'=>['nullable','string',Rule::in($contacts)],
            'declaration'=>'accepted',
            'attachments'=>'nullable|array|max:5',
            'attachments.*'=>'file|mimes:pdf,doc,docx,xls,xlsx,zip|max:10240',
        ]);

        if(($data['requirement_type']??'')!=='Networking Equipment') {
            $data['networking_requirements']=null;
        }
        if(! in_array($data['requirement_type']??'',$itTypes,true)) {
            $data['service_requirements']=null;
        }

        $paths=[];
        if($request->hasFile('attachments')) {
            foreach($request->file('attachments') as $file) {
                $paths[]=$file->store('quote-requests','public');
            }
        }

        $quote=QuoteRequest::create([
            ...collect($data)->except(['attachments','declaration'])->all(),
            'declaration'=>true,
            'attachments'=>$paths ?: null,
            'status'=>'new',
        ]);

        return response()->json([
            'message'=>'Your quotation request has been received successfully.',
            'quote'=>$quote,
        ],201);
    }

    public function sitemap()
    {
        $urls=collect([url('/'),url('/shop'),url('/blog'),url('/about'),url('/contact')])
            ->merge(Product::active()->pluck('slug')->map(fn($s)=>url("/product/$s")))
            ->merge(Post::published()->pluck('slug')->map(fn($s)=>url("/blog/$s")))
            ->merge(Page::where('is_active',true)->pluck('slug')->map(fn($s)=>url("/page/$s")));
        $xml='<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.
            $urls->unique()->map(fn($u)=>'<url><loc>'.htmlspecialchars($u,ENT_XML1).'</loc></url>')->implode('').'</urlset>';
        return response($xml,200,['Content-Type'=>'application/xml']);
    }

    public function robots() { return response("User-agent: *\nAllow: /\nDisallow: /api/cp/\nSitemap: ".url('/sitemap.xml')."\n",200,['Content-Type'=>'text/plain']); }

    private function resolveCoupon(?string $code, int $subtotalCents, bool $lock=false, array $lines=[]): array
    {
        if(!$code) return [null,0];
        $query=Coupon::where('code',Str::upper(trim($code)));
        if($lock) $query->lockForUpdate();
        $coupon=$query->first();
        if(!$coupon || !$coupon->is_active) throw ValidationException::withMessages(['coupon_code'=>['This coupon is invalid or inactive.']]);
        if($coupon->starts_at && $coupon->starts_at->isFuture()) throw ValidationException::withMessages(['coupon_code'=>['This coupon is not active yet.']]);
        if($coupon->expires_at && $coupon->expires_at->isPast()) throw ValidationException::withMessages(['coupon_code'=>['This coupon has expired.']]);
        if($coupon->usage_limit!==null && $coupon->used_count >= $coupon->usage_limit) throw ValidationException::withMessages(['coupon_code'=>['This coupon usage limit has been reached.']]);
        if($subtotalCents < (int) round(((float)$coupon->minimum_order)*100)) throw ValidationException::withMessages(['coupon_code'=>['Your order does not meet this coupon minimum.']]);

        // Optional rules: limit the coupon to a category and/or to one add-on (e.g. Warranty: 1 year).
        // With an add-on rule the discount is worked out on that add-on's price only, never on the product.
        $baseCents=$subtotalCents;
        if($coupon->addon_group || $coupon->category_id) {
            $baseCents=$this->couponEligibleCents($coupon,$lines);
            if($baseCents<=0) throw ValidationException::withMessages(['coupon_code'=>[$this->couponRuleMessage($coupon)]]);
        }

        $discountCents=$coupon->type==='percent'
            ? (int) round($baseCents*((float)$coupon->value)/100)
            : (int) round(((float)$coupon->value)*100);
        if($coupon->maximum_discount!==null) $discountCents=min($discountCents,(int) round(((float)$coupon->maximum_discount)*100));

        return [$coupon,min($discountCents,$baseCents)];
    }

    /** Amount (in cents) the coupon may discount: matching add-on prices, or whole lines when only a category is set. */
    private function couponEligibleCents(Coupon $coupon, array $lines): int
    {
        $cents=0;
        foreach($lines as $line) {
            $product=$line['product'];
            if($coupon->category_id && (int)$product->category_id!==(int)$coupon->category_id) continue;
            if(!$coupon->addon_group) { $cents+=(int)$line['total_cents']; continue; }

            $groups=ProductAddons::catalogMap(ProductAddons::catalogForProduct($product));
            foreach($line['summary'] ?? [] as $picked) {
                $groupLabel=$groups[$picked['group']]['label'] ?? '';
                if($this->couponLabel($groupLabel)!==$this->couponLabel($coupon->addon_group)) continue;
                if($coupon->addon_option && $this->couponLabel($picked['label'])!==$this->couponLabel($coupon->addon_option)) continue;
                $cents+=(int) round(((float)$picked['price'])*100)*(int)$line['quantity'];
            }
        }
        return $cents;
    }

    /** Case/space/plural-insensitive label match: "6 Months" = "6 month". */
    private function couponLabel(?string $label): string
    {
        $label=Str::lower(trim((string)$label));
        $label=preg_replace('/s\b/u','',$label);
        return preg_replace('/[^a-z0-9]/u','',$label);
    }

    private function couponRuleMessage(Coupon $coupon): string
    {
        $category=$coupon->category_id ? Category::find($coupon->category_id)?->name : null;
        if($coupon->addon_group) {
            $what=$coupon->addon_option ? "{$coupon->addon_group}: {$coupon->addon_option}" : $coupon->addon_group;
            return "Coupon {$coupon->code} only applies when {$what} is selected".($category ? " on a {$category} product." : '.');
        }
        return "Coupon {$coupon->code} only applies to {$category} products.";
    }
}
