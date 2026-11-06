<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            // Empty/null = coupon works on every category / the whole item price.
            $table->json('category_ids')->nullable()->after('maximum_discount');
            $table->json('addon_groups')->nullable()->after('category_ids');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn(['category_ids', 'addon_groups']);
        });
    }
};
