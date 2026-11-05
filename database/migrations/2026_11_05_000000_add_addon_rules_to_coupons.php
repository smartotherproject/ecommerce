<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            // Optional rules. When addon_group is set, the coupon discounts only that add-on's price.
            $table->foreignId('category_id')->nullable()->after('value')->constrained()->nullOnDelete();
            $table->string('addon_group', 80)->nullable()->after('category_id');
            $table->string('addon_option', 120)->nullable()->after('addon_group');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['addon_group', 'addon_option']);
        });
    }
};
