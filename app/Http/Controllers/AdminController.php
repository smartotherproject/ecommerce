<?php

namespace App\Http\Controllers;

use App\Models\{Banner, Brand, Category, Contact, Coupon, Faq, Order, Page, Post, Product, QuoteRequest, Setting, Subscriber, Testimonial};
use App\Support\ProductAddons;
use App\Support\Shipping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    private const MODELS=[
        'categories'=>Category::class,'brands'=>Brand::class,'products'=>Product::class,'posts'=>Post::class,'pages'=>Page::class,
        'banners'=>Banner::class,'faqs'=>Faq::class,'testimonials'=>Testimonial::class,'subscribers'=>Subscriber::class,'contacts'=>Contact::class,'coupons'=>Coupon::class,
    ];

    public function uploadMedia(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,gif,webp,avif,svg|max:6144',
        ]);
        $path = $request->file('file')->store('editor', 'public');

        return response()->json([
            'url' => Storage::disk('public')->url($path),
            'path' => $path,
        ], 201);
    }

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'counts'=>['products'=>Product::count(),'orders'=>Order::count(),'customers'=>Order::distinct('phone')->count('phone'),'subscribers'=>Subscriber::where('is_active',true)->count()],
            'orders'=>['pending'=>Order::where('status','pending')->count(),'today'=>Order::whereDate('created_at',today())->count(),'revenue'=>(string)Order::whereNotIn('status',['cancelled','refunded'])->sum('total')],
            'low_stock'=>Product::where('stock','<=',5)->orderBy('stock')->limit(10)->get(),
            'recent_orders'=>Order::latest()->limit(10)->get(),
        ]);
    }

    public function index(Request $request, string $resource): JsonResponse
    {
        $class=$this->model($resource); $q=$class::query();
        if($resource==='products') $q->with('category','brand');
        if($search=$request->string('search')->trim()->value()) {
            $column=$resource==='subscribers'?'email':($resource==='coupons'?'code':($resource==='contacts'?'name':($resource==='faqs'?'question':(in_array($resource,['categories','brands','products','testimonials'])?'name':'title'))));
            $q->where($column,'like',"%$search%");
        }
        return response()->json($q->latest()->paginate(min(max($request->integer('per_page',20),1),100)));
    }

    public function show(string $resource, int $id): JsonResponse
    {
        $record=$this->model($resource)::findOrFail($id);
        if($record instanceof Product) $record->load('category','brand');
        return response()->json(['data'=>$record]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $this->normalizeBooleans($request);
        if($resource==='coupons') $request->merge(['code'=>Str::upper(trim((string)$request->input('code')))]);
        $data=$request->validate($this->rules($resource));
        $data=$this->prepare($request,$resource,$data);
        return response()->json(['data'=>$this->model($resource)::create($data)],201);
    }

    public function update(Request $request, string $resource, int $id): JsonResponse
    {
        $record=$this->model($resource)::findOrFail($id);
        $this->normalizeBooleans($request);
        if($resource==='coupons') $request->merge(['code'=>Str::upper(trim((string)$request->input('code')))]);
        $data=$request->validate($this->rules($resource,$record));
        $record->update($this->prepare($request,$resource,$data,$record));
        return response()->json(['data'=>$record->fresh()]);
    }

    public function destroy(string $resource, int $id): JsonResponse
    {
        $record=$this->model($resource)::findOrFail($id);
        if($record instanceof Category && $record->products()->exists()) return response()->json(['message'=>'Category has products.'],409);
        if($record instanceof Brand && $record->products()->exists()) return response()->json(['message'=>'Brand has products.'],409);
        foreach(['image','logo'] as $field) if($record->{$field}??null) Storage::disk('public')->delete($record->{$field});
        if($record instanceof Product) Storage::disk('public')->delete($record->gallery??[]);
        $record->delete();
        return response()->json(null,204);
    }

    public function orders(Request $request): JsonResponse
    {
        $q=Order::withCount('items')->latest();
        $q->when($request->status,fn($q,$v)=>$q->where('status',$v));
        $q->when($request->search,fn($q,$v)=>$q->where(fn($x)=>$x->where('order_number','like',"%$v%")->orWhere('phone','like',"%$v%")->orWhere('customer_name','like',"%$v%")));
        return response()->json($q->paginate(min(max($request->integer('per_page',20),1),100)));
    }

    public function order(int $id): JsonResponse { return response()->json(['order'=>Order::with('items.product','statusHistory.user')->findOrFail($id)]); }

    public function orderStatus(Request $request, int $id): JsonResponse
    {
        $data=$request->validate(['status'=>['required',Rule::in(['pending','confirmed','processing','shipped','delivered','cancelled','refunded'])],'note'=>'nullable|string|max:1000']);
        $order=Order::findOrFail($id);
        DB::transaction(function() use($order,$data,$request) {
            $order->update(['status'=>$data['status']]);
            $order->statusHistory()->create(['status'=>$data['status'],'note'=>$data['note']??null,'user_id'=>$request->user()->id]);
        });
        return response()->json(['order'=>$order->fresh()->load('items','statusHistory.user')]);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data'=>collect(Setting::flat())->except(['vapid_private_key'])->all()]);
    }

    public function shipping(): JsonResponse
    {
        $rates=Shipping::rates();
        return response()->json(['data'=>[
            'karachi'=>$rates['karachi'],
            'other'=>$rates['other'],
            'shipping_karachi'=>$rates['karachi'],
            'shipping_other'=>$rates['other'],
            'cities'=>Shipping::cities(),
        ]]);
    }

    public function saveShipping(Request $request): JsonResponse
    {
        $data=$request->validate([
            'shipping_karachi'=>'required|numeric|min:0|max:100000',
            'shipping_other'=>'required|numeric|min:0|max:100000',
        ]);
        Setting::updateOrCreate(['key'=>'shipping_karachi'],['value'=>(string) round((float)$data['shipping_karachi'],2),'type'=>'number','group'=>'commerce']);
        Setting::updateOrCreate(['key'=>'shipping_other'],['value'=>(string) round((float)$data['shipping_other'],2),'type'=>'number','group'=>'commerce']);
        return $this->shipping();
    }

    public function quoteRequests(Request $request): JsonResponse
    {
        $data=$request->validate([
            'search'=>'nullable|string|max:120',
            'status'=>'nullable|in:new,in_progress,quoted,closed',
            'page'=>'nullable|integer|min:1',
            'per_page'=>'nullable|integer|min:1|max:50',
        ]);
        $q=QuoteRequest::query()->latest();
        $q->when($data['status']??null, fn($q,$v)=>$q->where('status',$v));
        $q->when($data['search']??null, function($q,$v) {
            $q->where(fn($x)=>$x->where('company_name','like',"%$v%")
                ->orWhere('contact_person','like',"%$v%")
                ->orWhere('email','like',"%$v%")
                ->orWhere('phone','like',"%$v%")
                ->orWhere('requirement_type','like',"%$v%"));
        });
        return response()->json($q->paginate($data['per_page']??15)->withQueryString());
    }

    public function quoteRequest(int $id): JsonResponse
    {
        return response()->json(['data'=>QuoteRequest::findOrFail($id)]);
    }

    public function updateQuoteRequest(Request $request, int $id): JsonResponse
    {
        $quote=QuoteRequest::findOrFail($id);
        $data=$request->validate([
            'status'=>'nullable|in:new,in_progress,quoted,closed',
            'admin_notes'=>'nullable|string|max:5000',
        ]);
        $quote->update($data);
        return response()->json(['message'=>'Quote request updated.','data'=>$quote->fresh()]);
    }

    public function exportQuoteRequests(Request $request): StreamedResponse
    {
        $data=$request->validate(['status'=>'nullable|in:new,in_progress,quoted,closed','search'=>'nullable|string|max:120']);
        $q=QuoteRequest::query()->latest();
        $q->when($data['status']??null, fn($q,$v)=>$q->where('status',$v));
        $q->when($data['search']??null, function($q,$v) {
            $q->where(fn($x)=>$x->where('company_name','like',"%$v%")
                ->orWhere('contact_person','like',"%$v%")
                ->orWhere('email','like',"%$v%")
                ->orWhere('phone','like',"%$v%"));
        });
        $rows=$q->get();

        $spreadsheet=new Spreadsheet;
        $sheet=$spreadsheet->getActiveSheet();
        $sheet->setTitle('Quote Requests');
        $headers=[
            'ID','Submitted At','Status','Company Name','Contact Person','Designation','Email','Phone',
            'Website','Business Address','Requirement Type','Preferred Brands','Quantity','Preferred Specs',
            'Budget Range','Networking Requirements','Service Requirements','Project Details',
            'Delivery City','Expected Delivery Date','Preferred Contact','Admin Notes',
        ];
        foreach($headers as $i=>$header) $sheet->setCellValue([$i+1,1],$header);

        foreach($rows as $r=>$quote) {
            $values=[
                $quote->id,
                optional($quote->created_at)->format('Y-m-d H:i'),
                $quote->status,
                $quote->company_name,
                $quote->contact_person,
                $quote->designation,
                $quote->email,
                $quote->phone,
                $quote->website,
                $quote->business_address,
                $quote->requirement_type,
                implode(', ',$quote->preferred_brands??[]),
                $quote->quantity,
                $quote->preferred_specs,
                $quote->budget_range,
                implode(', ',$quote->networking_requirements??[]),
                implode(', ',$quote->service_requirements??[]),
                $quote->project_details,
                $quote->delivery_city,
                optional($quote->expected_delivery_date)->format('Y-m-d'),
                $quote->preferred_contact,
                $quote->admin_notes,
            ];
            foreach($values as $c=>$value) $sheet->setCellValue([$c+1,$r+2],$value);
        }
        foreach(range(1,count($headers)) as $col) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        return response()->streamDownload(function() use($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'quote-requests-'.now()->format('Ymd-His').'.xlsx', [
            'Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function productImportTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Products');

        $headers = [
            'name', 'sku', 'category', 'brand', 'price', 'sale_price', 'stock', 'condition',
            'short_description', 'description', 'is_featured', 'is_sale', 'is_active',
            'seo_title', 'seo_description', 'specs',
        ];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }

        $demo = [
            ['NexBook Pro 14 Business Laptop', 'NX-DEMO-1401', 'Business Laptops', 'Dell', 189500, 174900, 8, 'new', 'Warranty-backed business laptop with clear specs.', 'A carefully selected device for professional workloads in Pakistan.', 1, 1, 1, 'NexBook Pro 14 in Pakistan', 'View price and specs for NexBook Pro 14.', '{"processor":"Intel Core i7","memory":"16GB","storage":"512GB SSD"}'],
            ['CreatorView 15 OLED Laptop', 'NX-DEMO-1502', 'Laptops', 'ASUS', 229000, '', 5, 'new', 'OLED creator laptop for design and media work.', 'Balanced performance with a vivid display for everyday creative work.', 1, 0, 1, 'CreatorView 15 OLED in Pakistan', 'Shop CreatorView 15 OLED laptop.', '{"processor":"Intel Core i7","memory":"16GB","storage":"1TB SSD","display":"15.6-inch OLED"}'],
            ['WorkMate 840 Refurbished', 'NX-DEMO-1404', 'Business Laptops', 'HP', 112000, 104500, 12, 'refurbished', 'Refurbished business laptop with reliable specs.', 'Practical refurbished option with transparent condition and warranty support.', 0, 1, 1, 'WorkMate 840 Refurbished', 'Refurbished HP laptop available at Nexify.', '{"processor":"Intel Core i5","memory":"16GB","storage":"512GB SSD"}'],
        ];
        foreach ($demo as $r => $row) {
            foreach ($row as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 2], $value);
            }
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'products-import-demo.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function importProducts(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
        ]);

        $path = $request->file('file')->getRealPath();
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        if (count($rows) < 2) {
            return response()->json(['message' => 'The spreadsheet is empty. Download the demo file and add product rows.'], 422);
        }

        $headers = array_map(fn ($h) => Str::of((string) $h)->lower()->replace([' ', '-'], '_')->trim()->value(), array_shift($rows));
        $imported = 0;
        $updated = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                $line = $index + 2;
                if ($this->rowIsEmpty($row)) {
                    continue;
                }

                $data = [];
                foreach ($headers as $i => $key) {
                    if ($key === '' || ! array_key_exists($i, $row)) {
                        continue;
                    }
                    $data[$key] = is_string($row[$i]) ? trim($row[$i]) : $row[$i];
                }

                try {
                    $payload = $this->mapProductImportRow($data);
                    $existing = Product::where('sku', $payload['sku'])->first();
                    if ($existing) {
                        $existing->update($payload);
                        $updated++;
                    } else {
                        Product::create($payload);
                        $imported++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = ['row' => $line, 'message' => $e->getMessage()];
                }
            }

            if (count($errors) && ($imported + $updated) === 0) {
                DB::rollBack();

                return response()->json([
                    'message' => 'No products were imported. Fix the errors and try again.',
                    'imported' => 0,
                    'updated' => 0,
                    'errors' => array_slice($errors, 0, 20),
                ], 422);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Import complete: {$imported} created, {$updated} updated".(count($errors) ? ', '.count($errors).' row(s) skipped' : '').'.',
            'imported' => $imported,
            'updated' => $updated,
            'errors' => array_slice($errors, 0, 20),
        ]);
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function mapProductImportRow(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $sku = trim((string) ($data['sku'] ?? ''));
        if ($name === '' || $sku === '') {
            throw new \InvalidArgumentException('name and sku are required.');
        }

        $categoryId = $this->resolveCategoryId($data);
        $brandId = $this->resolveBrandId($data);

        $price = $data['price'] ?? null;
        if ($price === null || $price === '') {
            throw new \InvalidArgumentException('price is required.');
        }

        $salePrice = $data['sale_price'] ?? null;
        $salePrice = ($salePrice === '' || $salePrice === null) ? null : (float) $salePrice;
        $price = (float) $price;
        if ($salePrice !== null && $salePrice > $price) {
            throw new \InvalidArgumentException('sale_price must be less than or equal to price.');
        }

        $condition = strtolower(trim((string) ($data['condition'] ?? 'new')));
        if (! in_array($condition, ['new', 'used', 'refurbished', 'open-box'], true)) {
            throw new \InvalidArgumentException('condition must be new, used, refurbished, or open-box.');
        }

        $specs = $data['specs'] ?? null;
        if (is_string($specs) && trim($specs) !== '') {
            $decoded = json_decode($specs, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('specs must be valid JSON.');
            }
            $specs = $decoded;
        } elseif ($specs === '' || $specs === null) {
            $specs = null;
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        $slug = $slug !== '' ? Str::slug($slug) : Str::slug($name);
        $base = $slug;
        $n = 1;
        while (Product::where('slug', $slug)->where('sku', '!=', $sku)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return [
            'name' => $name,
            'sku' => $sku,
            'slug' => $slug,
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'price' => $price,
            'sale_price' => $salePrice,
            'stock' => (int) ($data['stock'] ?? 0),
            'condition' => $condition,
            'short_description' => $this->nullableString($data['short_description'] ?? null),
            'description' => $this->nullableString($data['description'] ?? null),
            'seo_title' => $this->nullableString($data['seo_title'] ?? null),
            'seo_description' => $this->nullableString($data['seo_description'] ?? null),
            'specs' => $specs,
            'is_featured' => $this->toBool($data['is_featured'] ?? false),
            'is_sale' => $this->toBool($data['is_sale'] ?? ($salePrice !== null)),
            'is_active' => $this->toBool($data['is_active'] ?? true, true),
        ];
    }

    private function resolveCategoryId(array $data): int
    {
        if (! empty($data['category_id'])) {
            $id = (int) $data['category_id'];
            if (! Category::whereKey($id)->exists()) {
                throw new \InvalidArgumentException("category_id {$id} not found.");
            }

            return $id;
        }

        $name = trim((string) ($data['category'] ?? $data['category_name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('category or category_id is required.');
        }

        $category = Category::query()
            ->where(fn ($q) => $q->where('name', $name)->orWhere('slug', Str::slug($name)))
            ->first();
        if (! $category) {
            throw new \InvalidArgumentException("Category \"{$name}\" not found. Create it first or use an existing category name.");
        }

        return $category->id;
    }

    private function resolveBrandId(array $data): ?int
    {
        if (array_key_exists('brand_id', $data) && ($data['brand_id'] === '' || $data['brand_id'] === null)) {
            return null;
        }
        if (! empty($data['brand_id'])) {
            $id = (int) $data['brand_id'];
            if (! Brand::whereKey($id)->exists()) {
                throw new \InvalidArgumentException("brand_id {$id} not found.");
            }

            return $id;
        }

        $name = trim((string) ($data['brand'] ?? $data['brand_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $brand = Brand::query()
            ->where(fn ($q) => $q->where('name', $name)->orWhere('slug', Str::slug($name)))
            ->first();
        if (! $brand) {
            throw new \InvalidArgumentException("Brand \"{$name}\" not found. Create it first or leave brand empty.");
        }

        return $brand->id;
    }

    private function toBool(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y'], true);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data=$request->validate([
            'site_name'=>'nullable|string|max:120',
            'phone'=>'nullable|string|max:40',
            'email'=>'nullable|email|max:255',
            'address'=>'nullable|string|max:1000',
            'whatsapp'=>'nullable|string|max:40',
            'facebook'=>'nullable|url|max:500',
            'instagram'=>'nullable|url|max:500',
            'youtube'=>'nullable|url|max:500',
            'announcement'=>'nullable|string|max:500',
            'hero_title'=>'nullable|string|max:180',
            'hero_subtitle'=>'nullable|string|max:1000',
            'footer_text'=>'nullable|string|max:1000',
            'newsletter_title'=>'nullable|string|max:180',
            'meta_title'=>'nullable|string|max:255',
            'meta_description'=>'nullable|string|max:1000',
            'robots'=>'nullable|string|max:255',
            'google_analytics_id'=>'nullable|string|max:80',
            'header_scripts'=>'nullable|string|max:50000',
            'footer_scripts'=>'nullable|string|max:50000',
            'primary_color'=>['nullable','regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color'=>['nullable','regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_color'=>['nullable','regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo'=>['nullable','file','max:5120'],
            'favicon'=>['nullable','file','max:4096'],
        ]);

        foreach (['logo', 'favicon'] as $key) {
            if ($request->hasFile($key)) {
                $file = $request->file($key);
                $mime = strtolower((string) $file->getMimeType());
                $ext = strtolower((string) $file->getClientOriginalExtension());
                $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico', 'svg'];
                $allowedMime = str_starts_with($mime, 'image/')
                    || in_array($mime, ['image/x-icon', 'image/vnd.microsoft.icon', 'application/octet-stream'], true);
                if (! $allowedMime && ! in_array($ext, $allowedExt, true)) {
                    throw ValidationException::withMessages([
                        $key => ["The {$key} must be an image file (PNG, JPG, WEBP, ICO, etc.)."],
                    ]);
                }
            }
        }

        $groups=[
            'site_name'=>'branding','primary_color'=>'theme','secondary_color'=>'theme','dark_color'=>'theme',
            'phone'=>'contact','email'=>'contact','address'=>'contact','whatsapp'=>'contact','facebook'=>'social','instagram'=>'social','youtube'=>'social',
            'announcement'=>'homepage','hero_title'=>'homepage','hero_subtitle'=>'homepage','newsletter_title'=>'homepage','footer_text'=>'footer',
            'meta_title'=>'seo','meta_description'=>'seo','robots'=>'seo','google_analytics_id'=>'seo',
            'header_scripts'=>'tracking','footer_scripts'=>'tracking',
        ];
        foreach($groups as $key=>$group) if(array_key_exists($key,$data)) {
            $type = match (true) {
                str_ends_with($key, '_color') => 'color',
                in_array($key, ['header_scripts', 'footer_scripts'], true) => 'html',
                default => 'text',
            };
            Setting::updateOrCreate(['key'=>$key],['value'=>$data[$key],'type'=>$type,'group'=>$group]);
        }
        foreach(['logo','favicon'] as $key) if($request->hasFile($key)) {
            $old=Setting::where('key',$key)->value('value'); if($old) Storage::disk('public')->delete($old);
            Setting::updateOrCreate(['key'=>$key],['value'=>$request->file($key)->store('settings','public'),'type'=>'image','group'=>'branding']);
        }
        return $this->settings();
    }

    private function model(string $resource): string { abort_unless(isset(self::MODELS[$resource]),404); return self::MODELS[$resource]; }

    private function normalizeBooleans(Request $request): void
    {
        foreach(['is_active','is_featured','is_sale','is_read','addons_enabled'] as $field) {
            if($request->has($field)) $request->merge([$field=>$request->boolean($field)]);
        }
    }

    private function rules(string $r, ?Model $record=null): array
    {
        $unique=fn($table,$column='slug')=>Rule::unique($table,$column)->ignore($record?->getKey());
        return match($r) {
            'categories'=>['name'=>'required|string|max:120','slug'=>['nullable','string','max:140',$unique('categories')],'description'=>'nullable|string','image'=>'nullable|file|mimes:jpg,jpeg,png,gif,webp,avif|max:4096','sort_order'=>'nullable|integer|min:0','is_active'=>'nullable|boolean'],
            'brands'=>['name'=>'required|string|max:120','slug'=>['nullable','string','max:140',$unique('brands')],'description'=>'nullable|string','logo'=>'nullable|file|mimes:jpg,jpeg,png,gif,webp,avif|max:4096','is_active'=>'nullable|boolean'],
            'products'=>['category_id'=>'required|exists:categories,id','brand_id'=>'nullable|exists:brands,id','name'=>'required|string|max:180','slug'=>['nullable','string','max:200',$unique('products')],
                'sku'=>['required','string','max:80',$unique('products','sku')],'price'=>'required|numeric|min:0|max:9999999999','sale_price'=>'nullable|numeric|min:0|lte:price','stock'=>'required|integer|min:0',
                'condition'=>'required|in:new,used,refurbished,open-box','addons_enabled'=>'nullable|boolean','addons'=>'nullable',
                'is_featured'=>'nullable|boolean','is_sale'=>'nullable|boolean','is_active'=>'nullable|boolean',
                'image'=>'nullable|file|mimes:jpg,jpeg,png,gif,webp,avif|max:6144','gallery'=>'nullable|array|max:10','gallery.*'=>'file|mimes:jpg,jpeg,png,gif,webp,avif|max:6144','specs'=>'nullable','short_description'=>'nullable|string|max:1000','description'=>'nullable|string',
                'seo_title'=>'nullable|string|max:255','seo_description'=>'nullable|string|max:1000'],
            'posts','pages'=>['title'=>'required|string|max:180','slug'=>['nullable','string','max:200',$unique($r)],'excerpt'=>'nullable|string|max:1000','content'=>'nullable|string','image'=>'nullable|file|mimes:jpg,jpeg,png,gif,webp,avif|max:6144',
                'seo_title'=>'nullable|string|max:255','seo_description'=>'nullable|string|max:1000','is_active'=>'nullable|boolean','published_at'=>$r==='posts'?'nullable|date':'nullable'],
            'banners'=>['title'=>'required|string|max:180','subtitle'=>'nullable|string|max:255','image'=>'nullable|file|mimes:jpg,jpeg,png,gif,webp,avif|max:6144','button_text'=>'nullable|string|max:80','url'=>'nullable|string|max:500','position'=>'nullable|string|max:80','sort_order'=>'nullable|integer|min:0','is_active'=>'nullable|boolean'],
            'faqs'=>['question'=>'required|string|max:500','answer'=>'required|string|max:5000','sort_order'=>'nullable|integer|min:0','is_active'=>'nullable|boolean'],
            'testimonials'=>['name'=>'required|string|max:120','role'=>'nullable|string|max:120','content'=>'required|string|max:2000','rating'=>'nullable|integer|min:1|max:5','image'=>'nullable|file|mimes:jpg,jpeg,png,gif,webp,avif|max:4096','is_active'=>'nullable|boolean'],
            'subscribers'=>['email'=>['required','email','max:255',$unique('subscribers','email')],'is_active'=>'nullable|boolean'],
            'coupons'=>['code'=>['required','string','max:50',$unique('coupons','code')],'type'=>'required|in:percent,fixed','value'=>['required','numeric','min:0.01',Rule::when(request('type')==='percent','max:100')],
                'category_id'=>'nullable|integer|exists:categories,id','addon_group'=>'nullable|string|max:80|required_with:addon_option','addon_option'=>'nullable|string|max:120',
                'minimum_order'=>'nullable|numeric|min:0','maximum_discount'=>'nullable|numeric|min:0.01','usage_limit'=>'nullable|integer|min:1',
                'starts_at'=>'nullable|date','expires_at'=>'nullable|date|after:starts_at','is_active'=>'nullable|boolean'],
            'contacts'=>['name'=>'required|string|max:120','email'=>'nullable|email|max:255','phone'=>'nullable|string|max:30','subject'=>'nullable|string|max:180','message'=>'required|string|max:5000','is_read'=>'nullable|boolean'],
            default=>[],
        };
    }

    private function prepare(Request $request,string $r,array $data,?Model $record=null): array
    {
        if(array_key_exists('slug',$data)||in_array($r,['categories','brands','products','posts','pages'])) $data['slug']=$data['slug']??Str::slug($data['name']??$data['title']);
        if($r==='coupons' && isset($data['code'])) $data['code']=Str::upper(trim($data['code']));
        foreach(['is_active','is_featured','is_sale','is_read','addons_enabled'] as $field) if($request->has($field)) $data[$field]=$request->boolean($field);
        foreach(['image','logo'] as $field) if($request->hasFile($field)) {
            if($record?->{$field}) Storage::disk('public')->delete($record->{$field});
            $data[$field]=$request->file($field)->store($r,'public');
        }
        if($r==='products') {
            if(isset($data['specs'])&&is_string($data['specs'])) $data['specs']=json_decode($data['specs'],true,512,JSON_THROW_ON_ERROR);
            if($request->hasFile('gallery')) {
                Storage::disk('public')->delete($record?->gallery??[]);
                $data['gallery']=collect($request->file('gallery'))->map(fn($f)=>$f->store('products/gallery','public'))->all();
            }
            $data['addons_enabled']=$request->boolean('addons_enabled');
            $data['addons']=$data['addons_enabled']
                ? ProductAddons::sanitize($data['addons'] ?? $request->input('addons'))
                : [];
        }
        return $data;
    }
}
