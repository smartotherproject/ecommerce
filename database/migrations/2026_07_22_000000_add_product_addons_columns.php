<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Recreated: this migration already ran on production but the file was missing from the repo.
// The next migration (product_custom_addons_catalog) expects these columns.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'addons_enabled')) {
                $table->boolean('addons_enabled')->default(false)->after('condition');
            }
            if (! Schema::hasColumn('products', 'enabled_addons')) {
                $table->json('enabled_addons')->nullable()->after('addons_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['enabled_addons', 'addons_enabled'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
