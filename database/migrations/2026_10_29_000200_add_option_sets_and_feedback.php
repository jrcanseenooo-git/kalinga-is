<?php

use App\Services\OptionSets;
use App\Services\RolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.
 * - Editable form options: the starting lists of config/option_sets.php
 *   are written once into reference_data (existing options are kept).
 * - Supplemental fields: cases.custom_fields keeps the answers to the
 *   fields an administrator adds to the intake (form_fields).
 * - user_feedback: suggestions and problem reports from users, with the page
 *   they came from and the reply.
 * - New permission codes (options.manage, feedback.review, connect.network).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('cases', 'custom_fields')) {
            Schema::table('cases', fn (Blueprint $table) => $table->json('custom_fields')->nullable());
        }
        if (!Schema::hasColumn('form_fields', 'help_text')) {
            Schema::table('form_fields', function (Blueprint $table) {
                $table->string('help_text', 255)->nullable();
                $table->boolean('active')->default(true);
            });
        }
        Schema::create('user_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20);
            $table->string('subject', 160);
            $table->text('message');
            $table->string('page', 160)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('status', 20)->default('new');
            $table->text('response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        foreach (RolePermissions::PERMISSIONS as $permission) {
            if (Schema::hasTable('permissions')) {
                DB::table('permissions')->updateOrInsert(['code' => $permission], ['name' => str_replace('.', ' ', ucwords($permission, '.')), 'module' => strtok($permission, '.'), 'updated_at' => now()]);
            }
        }
        OptionSets::ensureDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_feedback');
        if (Schema::hasColumn('form_fields', 'help_text')) {
            Schema::table('form_fields', fn (Blueprint $table) => $table->dropColumn(['help_text', 'active']));
        }
        if (Schema::hasColumn('cases', 'custom_fields')) {
            Schema::table('cases', fn (Blueprint $table) => $table->dropColumn('custom_fields'));
        }
    }
};
