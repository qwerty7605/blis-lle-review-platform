<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Topics and sub-topics come from the LLE Table of Specifications
 * (docs/lle_tos.json, App\Support\LleTaxonomy). Each keeps its TOS code, the
 * number of board questions the TOS gives it (reference data; nothing has to
 * use it yet) and its place in the TOS, so lists follow the TOS order rather
 * than the alphabet.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['topics', 'subtopics'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('code', 20)->nullable()->after('name');
                $blueprint->unsignedSmallInteger('tos_items')->nullable()->after('code');
                $blueprint->unsignedSmallInteger('sort_order')->nullable()->after('tos_items');
            });
        }
    }

    public function down(): void
    {
        foreach (['topics', 'subtopics'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['code', 'tos_items', 'sort_order']);
            });
        }
    }
};
