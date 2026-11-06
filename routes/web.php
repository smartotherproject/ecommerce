<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminBackupController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminPushController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\StoreController;
use App\Models\{Page, Post, Product, Setting};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::get('/csrf-token', fn () => response()->json(['csrf_token'=>csrf_token()]));
    Route::get('/storefront', [StoreController::class, 'storefront']);
    Route::get('/products', [StoreController::class, 'products']);
    Route::get('/products/suggest', [StoreController::class, 'searchSuggest'])->middleware('throttle:60,1');
    Route::get('/products/{slug}', [StoreController::class, 'product']);
    Route::get('/posts', [StoreController::class, 'posts']);
    Route::get('/posts/{slug}', [StoreController::class, 'post']);
    Route::get('/pages/{slug}', [StoreController::class, 'page']);
    Route::post('/coupons/validate', [StoreController::class, 'coupon'])->middleware('throttle:20,1');
    Route::post('/orders', [StoreController::class, 'order'])->middleware('throttle:20,1');
    Route::get('/orders/{number}', [StoreController::class, 'orderSummary'])->where('number','[A-Za-z0-9-]+')->middleware('throttle:30,1');
    Route::post('/subscribers', [StoreController::class, 'subscribe'])->middleware('throttle:10,1');
    Route::post('/contact', [StoreController::class, 'contact'])->middleware('throttle:10,1');
    Route::post('/quote-requests', [StoreController::class, 'quoteRequest'])->middleware('throttle:8,1');
    Route::get('/push/config', [PushController::class, 'config']);
    Route::post('/push/subscribe', [PushController::class, 'subscribe'])->middleware('throttle:30,1');
    Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe'])->middleware('throttle:30,1');

    Route::prefix('cp')->group(function () {
        Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1');
        Route::middleware(['auth','admin'])->group(function () {
            Route::post('/logout', [AdminAuthController::class, 'logout']);
            Route::get('/user', [AdminAuthController::class, 'user']);
            Route::get('/dashboard', [AdminController::class, 'dashboard']);
            Route::get('/orders', [AdminController::class, 'orders']);
            Route::get('/orders/{id}', [AdminController::class, 'order'])->whereNumber('id');
            Route::patch('/orders/{id}/status', [AdminController::class, 'orderStatus'])->whereNumber('id');
            Route::get('/orders/{id}/invoice', [AdminController::class, 'order'])->whereNumber('id');
            Route::get('/settings', [AdminController::class, 'settings']);
            Route::match(['put','post'], '/settings', [AdminController::class, 'saveSettings']);
            Route::get('/backup', [AdminBackupController::class, 'info']);
            Route::get('/backup/database/download', [AdminBackupController::class, 'downloadDatabase']);
            Route::post('/backup/database/restore', [AdminBackupController::class, 'restoreDatabase'])->middleware('throttle:3,1');
            Route::get('/backup/storage/download', [AdminBackupController::class, 'downloadStorage']);
            Route::post('/backup/storage/restore', [AdminBackupController::class, 'restoreStorage'])->middleware('throttle:3,1');
            Route::get('/shipping', [AdminController::class, 'shipping']);
            Route::match(['put','post'], '/shipping', [AdminController::class, 'saveShipping']);
            Route::get('/quote-requests', [AdminController::class, 'quoteRequests']);
            Route::get('/quote-requests/export', [AdminController::class, 'exportQuoteRequests']);
            Route::get('/quote-requests/{id}', [AdminController::class, 'quoteRequest'])->whereNumber('id');
            Route::patch('/quote-requests/{id}', [AdminController::class, 'updateQuoteRequest'])->whereNumber('id');
            Route::post('/media', [AdminController::class, 'uploadMedia']);
            Route::get('/coupon-options', [AdminController::class, 'couponOptions']);
            Route::get('/products/import-template', [AdminController::class, 'productImportTemplate']);
            Route::post('/products/import', [AdminController::class, 'importProducts']);
            Route::get('/push', [AdminPushController::class, 'overview']);
            Route::post('/push/keys', [AdminPushController::class, 'generateKeys']);
            Route::post('/push/settings', [AdminPushController::class, 'saveSettings']);
            Route::get('/push/notifications', [AdminPushController::class, 'notifications']);
            Route::post('/push/notifications', [AdminPushController::class, 'store']);
            Route::post('/push/notifications/{id}/send', [AdminPushController::class, 'send'])->whereNumber('id');
            Route::delete('/push/notifications/{id}', [AdminPushController::class, 'destroyNotification'])->whereNumber('id');
            Route::get('/push/subscribers', [AdminPushController::class, 'subscribers']);
            Route::delete('/push/subscribers/{id}', [AdminPushController::class, 'destroySubscriber'])->whereNumber('id');
            Route::get('/{resource}', [AdminController::class, 'index']);
            Route::post('/{resource}', [AdminController::class, 'store']);
            Route::get('/{resource}/{id}', [AdminController::class, 'show'])->whereNumber('id');
            Route::match(['put','patch','post'], '/{resource}/{id}', [AdminController::class, 'update'])->whereNumber('id');
            Route::delete('/{resource}/{id}', [AdminController::class, 'destroy'])->whereNumber('id');
        });
    });
});

Route::get('/sitemap.xml', [StoreController::class, 'sitemap']);
Route::get('/robots.txt', [StoreController::class, 'robots']);

Route::fallback(function (Request $request) {
    abort_if($request->is('api/*') || $request->is('storage/*'), 404);

    $settings=Setting::flat();
    $title=$settings['meta_title']??'Nexify System | Laptops & IT Solutions';
    $description=$settings['meta_description']??'Dependable technology, delivered across Pakistan.';
    $image=$settings['logo']??asset('nexify-logo.png');
    $schema=['@context'=>'https://schema.org','@type'=>'Organization','name'=>$settings['site_name']??'Nexify System','url'=>url('/'),'logo'=>$image];

    if($request->is('product/*')) {
        $product=Product::active()->where('slug',$request->segment(2))->first();
        if($product) {
            $title=$product->seo_title?:$product->name.' | Nexify System';
            $description=$product->seo_description?:$product->short_description?:$description;
            $image=$product->image_url?:$image;
            $schema=['@context'=>'https://schema.org','@type'=>'Product','name'=>$product->name,'sku'=>$product->sku,'description'=>$description,'image'=>$image,
                'offers'=>['@type'=>'Offer','priceCurrency'=>'PKR','price'=>$product->effective_price,'availability'=>$product->stock>0?'https://schema.org/InStock':'https://schema.org/OutOfStock','url'=>$request->fullUrl()]];
        }
    } elseif($request->is('blog/*')) {
        $post=Post::published()->where('slug',$request->segment(2))->first();
        if($post) {
            $title=$post->seo_title?:$post->title.' | Nexify System';
            $description=$post->seo_description?:$post->excerpt?:$description;
            $schema=['@context'=>'https://schema.org','@type'=>'Article','headline'=>$post->title,'description'=>$description,'datePublished'=>optional($post->published_at)->toIso8601String()];
        }
    } elseif($request->is('page/*')) {
        $page=Page::where('is_active',true)->where('slug',$request->segment(2))->first();
        if($page) {
            $title=$page->seo_title?:$page->title.' | Nexify System';
            $description=$page->seo_description?:(string) str($page->content)->stripTags()->limit(155);
        }
    }

    return view('app',compact('title','description','image','schema')+[
        'analyticsId'=>$settings['google_analytics_id']??null,
        'favicon'=>$settings['favicon']??null,
        'headerScripts'=>$settings['header_scripts']??null,
        'footerScripts'=>$settings['footer_scripts']??null,
    ]);
});
