<?php

namespace Tests\Feature;

use App\Models\{Brand, Category, Coupon, Product, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NexifyBackendTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_filters_active_products(): void
    {
        $category=Category::create(['name'=>'Laptops','slug'=>'laptops']);
        $brand=Brand::create(['name'=>'Nexify','slug'=>'nexify']);
        Product::create(['category_id'=>$category->id,'brand_id'=>$brand->id,'name'=>'Active Laptop','slug'=>'active-laptop','sku'=>'A-1','price'=>100000,'stock'=>2,'is_active'=>true]);
        Product::create(['category_id'=>$category->id,'brand_id'=>$brand->id,'name'=>'Hidden Laptop','slug'=>'hidden-laptop','sku'=>'H-1','price'=>90000,'stock'=>2,'is_active'=>false]);

        $this->getJson('/api/products?category=laptops&search=Active')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.slug','active-laptop');
        Product::create(['category_id'=>$category->id,'brand_id'=>$brand->id,'name'=>'Budget Laptop','slug'=>'budget-laptop','sku'=>'B-1','price'=>5500,'stock'=>2,'is_active'=>true]);
        $this->getJson('/api/products?min_price=5000&max_price=6000')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.slug','budget-laptop');
        $this->getJson('/api/products/active-laptop')->assertOk()->assertJsonPath('product.sku','A-1');
        $this->getJson('/api/storefront')->assertOk()->assertJsonStructure(['settings','categories','brands','banners','featured_products','sale_products','testimonials','faqs','latest_posts']);
    }

    public function test_admin_routes_require_admin_and_login_uses_session(): void
    {
        $this->getJson('/api/cp/dashboard')->assertUnauthorized();
        $admin=User::create(['name'=>'Admin','email'=>'admin@test.test','password'=>'secret-password','is_admin'=>true]);

        $this->postJson('/api/cp/login',['email'=>$admin->email,'password'=>'wrong'])->assertUnprocessable();
        $this->postJson('/api/cp/login',['email'=>$admin->email,'password'=>'secret-password'])->assertOk()->assertJsonPath('user.is_admin',true);
        $this->getJson('/api/cp/user')->assertOk()->assertJsonPath('user.email',$admin->email);
        $this->postJson('/api/cp/banners',['title'=>'Boolean Test','is_active'=>'true'])->assertCreated()->assertJsonPath('data.is_active',true);
        $this->postJson('/api/cp/logout')->assertOk();
    }

    public function test_cod_checkout_uses_server_price_and_decrements_stock(): void
    {
        $category=Category::create(['name'=>'Laptops','slug'=>'laptops']);
        $product=Product::create(['category_id'=>$category->id,'name'=>'Sale Laptop','slug'=>'sale-laptop','sku'=>'SALE-1','price'=>120000,'sale_price'=>110000,'is_sale'=>true,'stock'=>3,'is_active'=>true]);
        $response=$this->postJson('/api/orders',[
            'customer_name'=>'Ali Raza','phone'=>'03001234567','city'=>'Lahore','address'=>'House 10, Model Town, Lahore',
            'items'=>[['product_id'=>$product->id,'quantity'=>2,'price'=>1]],
        ]);
        $response->assertCreated()->assertJsonPath('order.total','220000.00')->assertJsonPath('order.items.0.unit_price','110000.00');
        $this->assertDatabaseHas('products',['id'=>$product->id,'stock'=>1]);
        $this->assertDatabaseHas('orders',['phone'=>'03001234567','payment_method'=>'cod','status'=>'pending']);
    }

    public function test_coupon_is_validated_and_applied_to_server_calculated_total(): void
    {
        $category=Category::create(['name'=>'Deals','slug'=>'deals']);
        $product=Product::create(['category_id'=>$category->id,'name'=>'Coupon Laptop','slug'=>'coupon-laptop','sku'=>'CP-1','price'=>100000,'stock'=>2,'is_active'=>true]);
        $coupon=Coupon::create(['code'=>'SAVE10','type'=>'percent','value'=>10,'minimum_order'=>50000,'maximum_discount'=>15000,'usage_limit'=>2,'is_active'=>true]);
        $items=[['product_id'=>$product->id,'quantity'=>1]];

        $this->postJson('/api/coupons/validate',['coupon_code'=>'save10','items'=>$items])
            ->assertOk()->assertJsonPath('discount','10000.00')->assertJsonPath('total','90000.00');

        $this->postJson('/api/orders',[
            'customer_name'=>'Sara Khan','phone'=>'03001234567','city'=>'Karachi','address'=>'House 20, Clifton, Karachi',
            'coupon_code'=>'SAVE10','items'=>$items,
        ])->assertCreated()->assertJsonPath('order.discount','10000.00')->assertJsonPath('order.total','90000.00');
        $this->assertDatabaseHas('coupons',['id'=>$coupon->id,'used_count'=>1]);
    }

    public function test_addon_coupon_only_discounts_the_matching_addon(): void
    {
        // The live database has products.addons_enabled; the repo's migrations don't create it, so add it for this test.
        if(!\Illuminate\Support\Facades\Schema::hasColumn('products','addons_enabled')) {
            \Illuminate\Support\Facades\Schema::table('products',fn($t)=>$t->boolean('addons_enabled')->default(false));
        }
        $laptops=Category::create(['name'=>'Used Laptops','slug'=>'used-laptops']);
        $phones=Category::create(['name'=>'Mobile Phones','slug'=>'mobile-phones']);
        $warranty=['id'=>'w','label'=>'Warranty','options'=>[['id'=>'m3','label'=>'3 months','price'=>0],['id'=>'m6','label'=>'6 months','price'=>10000],['id'=>'y1','label'=>'1 year','price'=>16000]]];
        $laptop=Product::create(['category_id'=>$laptops->id,'name'=>'Dell Latitude','slug'=>'dell-latitude','sku'=>'DL-1','price'=>37000,'stock'=>5,'is_active'=>true,'addons_enabled'=>true,'addons'=>[$warranty]]);
        $phone=Product::create(['category_id'=>$phones->id,'name'=>'iPhone 15','slug'=>'iphone-15','sku'=>'IP-1','price'=>210000,'stock'=>5,'is_active'=>true,'addons_enabled'=>true,'addons'=>[$warranty]]);
        Coupon::create(['code'=>'LAPTOP1Y','type'=>'fixed','value'=>11000,'category_id'=>$laptops->id,'addon_group'=>'Warranty','addon_option'=>'1 Year','is_active'=>true]);
        Coupon::create(['code'=>'LAPTOP6M','type'=>'percent','value'=>100,'category_id'=>$laptops->id,'addon_group'=>'warranty','addon_option'=>'6 month','is_active'=>true]);

        // Laptop + 1 year: Rs 11,000 off the warranty only (37,000 + 16,000 - 11,000).
        $this->postJson('/api/coupons/validate',['coupon_code'=>'LAPTOP1Y','items'=>[['product_id'=>$laptop->id,'quantity'=>1,'addons'=>['w'=>'y1']]]])
            ->assertOk()->assertJsonPath('discount','11000.00')->assertJsonPath('total','42000.00');

        // 100% coupon on the 6-month warranty never touches the laptop price.
        $this->postJson('/api/coupons/validate',['coupon_code'=>'LAPTOP6M','items'=>[['product_id'=>$laptop->id,'quantity'=>1,'addons'=>['w'=>'m6']]]])
            ->assertOk()->assertJsonPath('discount','10000.00')->assertJsonPath('total','37000.00');

        // Wrong warranty, no warranty, or wrong category: refused.
        $this->postJson('/api/coupons/validate',['coupon_code'=>'LAPTOP1Y','items'=>[['product_id'=>$laptop->id,'quantity'=>1,'addons'=>['w'=>'m6']]]])->assertStatus(422);
        $this->postJson('/api/coupons/validate',['coupon_code'=>'LAPTOP1Y','items'=>[['product_id'=>$laptop->id,'quantity'=>1]]])->assertStatus(422);
        $this->postJson('/api/coupons/validate',['coupon_code'=>'LAPTOP1Y','items'=>[['product_id'=>$phone->id,'quantity'=>1,'addons'=>['w'=>'y1']]]])->assertStatus(422);

        // A fixed coupon is applied once and capped at the add-on price, even with an expensive cart.
        $this->postJson('/api/orders',[
            'customer_name'=>'Ali Raza','phone'=>'03001234567','city'=>'Karachi','address'=>'House 20, Clifton, Karachi','coupon_code'=>'LAPTOP1Y',
            'items'=>[['product_id'=>$laptop->id,'quantity'=>2,'addons'=>['w'=>'y1']],['product_id'=>$phone->id,'quantity'=>1]],
        ])->assertCreated()->assertJsonPath('order.discount','11000.00');
    }

    public function test_seo_routes_return_expected_content_types(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type','application/xml');
        $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type','text/plain; charset=UTF-8')->assertSee('Sitemap:');
    }

    public function test_database_seeder_builds_a_working_catalog(): void
    {
        $this->seed();
        $this->assertDatabaseHas('users',['email'=>'admin@nexify.pk','is_admin'=>true]);
        $this->assertDatabaseCount('products',6);
        $this->getJson('/api/storefront')->assertOk()->assertJsonCount(4,'featured_products');
    }
}
