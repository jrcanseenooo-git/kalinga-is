<?php

use App\Services\RolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->string('sensitivity_level', 32)->default('general');
            $table->boolean('restricted')->default(false);
            $table->boolean('policy_validated')->default(false);
            $table->boolean('active')->default(true);
            $table->text('owner_notes')->nullable();
            $table->timestamps();
        });
        Schema::create('retention_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->string('record_type', 64);
            $table->foreignId('record_classification_id')->nullable()->constrained('record_classifications')->nullOnDelete();
            $table->string('case_status')->nullable();
            $table->unsignedInteger('review_after_days')->nullable();
            $table->boolean('policy_validated')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('record_governance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->string('record_type', 64);
            $table->unsignedBigInteger('record_id');
            $table->foreignId('record_classification_id')->nullable()->constrained('record_classifications')->nullOnDelete();
            $table->foreignId('retention_rule_id')->nullable()->constrained('retention_rules')->nullOnDelete();
            $table->string('lifecycle_state', 40)->default('active');
            $table->timestamp('retention_review_at')->nullable();
            $table->string('scan_state', 32)->default('legacy_unverified');
            $table->boolean('legacy_record')->default(false);
            $table->foreignId('classified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();
            $table->unique(['record_type', 'record_id']);
            $table->index(['case_id', 'lifecycle_state']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('lineage_id')->nullable()->after('id');
            $table->foreignId('previous_document_id')->nullable()->after('version')->constrained('documents')->nullOnDelete();
            $table->string('source_module', 64)->nullable()->after('uploaded_by');
            $table->text('uploader_notes')->nullable()->after('source_module');
            $table->index(['case_id', 'lineage_id']);
        });
        // Legacy file rows remain readable and are deliberately not inferred into
        // filename-based lineages. They receive only a safe legacy governance state.
        DB::table('documents')->orderBy('id')->chunkById(200, function ($documents): void {
            foreach ($documents as $document) {
                DB::table('record_governance')->insert([
                    'case_id' => $document->case_id, 'record_type' => 'document', 'record_id' => $document->id,
                    'lifecycle_state' => 'active', 'scan_state' => 'legacy_unverified', 'legacy_record' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        Schema::create('legal_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 24);
            $table->string('reason_code', 120);
            $table->string('authority', 255);
            $table->foreignId('placed_by')->constrained('users');
            $table->timestamp('placed_at');
            $table->timestamp('review_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 1000)->nullable();
            $table->timestamps();
        });
        Schema::create('legal_hold_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_hold_id')->constrained('legal_holds')->cascadeOnDelete();
            $table->string('record_type', 64);
            $table->unsignedBigInteger('record_id');
            $table->timestamps();
            $table->unique(['legal_hold_id', 'record_type', 'record_id']);
        });
        Schema::create('record_dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_governance_id')->constrained('record_governance')->cascadeOnDelete();
            $table->string('status', 40)->default('retention_review');
            $table->string('proposal_reason', 1000)->nullable();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('proposed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('record_disposition_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_disposition_id')->constrained('record_dispositions')->cascadeOnDelete();
            $table->string('event', 48);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_code', 120)->nullable();
            $table->timestamps();
        });
        Schema::create('record_disclosures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->string('recipient_organization', 255);
            $table->string('recipient_name', 255)->nullable();
            $table->string('purpose', 500);
            $table->string('lawful_authority', 255);
            $table->string('delivery_method', 100);
            $table->foreignId('authorized_by')->constrained('users');
            $table->timestamp('authorized_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });
        Schema::create('record_disclosure_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_disclosure_id')->constrained('record_disclosures')->cascadeOnDelete();
            $table->string('record_type', 64);
            $table->unsignedBigInteger('record_id');
            $table->string('category', 120);
            $table->timestamps();
        });
        foreach (['records.classify', 'records.restricted_view', 'records.retention.review', 'records.hold.manage', 'records.disposition.propose', 'records.disposition.review', 'records.disposition.approve', 'records.disclosure.record'] as $code) {
            DB::table('permissions')->updateOrInsert(['code' => $code], ['name' => str_replace('.', ' ', ucfirst($code)), 'module' => 'records', 'created_at' => now(), 'updated_at' => now()]);
        }
        $ids = DB::table('permissions')->where('module', 'records')->pluck('id', 'code');
        foreach (RolePermissions::ROLES as $role => $_) {
            $roleId = DB::table('roles')->where('code', $role)->value('id');
            if (!$roleId) {
                continue;
            } foreach ((new RolePermissions)->rolePermissions($role) as $permission) {
                if (isset($ids[$permission])) {
                    DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $ids[$permission]], []);
                }
            }
        }
        foreach ([['general_case', 'General case record', 'general', false], ['pii', 'Personal information', 'pii', false], ['spi', 'Sensitive personal information', 'spi', true], ['restricted', 'Restricted record', 'restricted', true], ['health_srh', 'Health / SRH', 'restricted', true], ['child_participation', 'Child participation', 'restricted', true], ['legal_evidentiary', 'Legal / evidentiary', 'restricted', true], ['administrative', 'Administrative', 'general', false]] as [$code,$label,$level,$restricted]) {
            DB::table('record_classifications')->insert(['code' => $code, 'label' => $label, 'sensitivity_level' => $level, 'restricted' => $restricted, 'policy_validated' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('record_disclosure_items');
        Schema::dropIfExists('record_disclosures');
        Schema::dropIfExists('record_disposition_history');
        Schema::dropIfExists('record_dispositions');
        Schema::dropIfExists('legal_hold_records');
        Schema::dropIfExists('legal_holds');
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn(['lineage_id', 'previous_document_id', 'source_module', 'uploader_notes']));
        Schema::dropIfExists('record_governance');
        Schema::dropIfExists('retention_rules');
        Schema::dropIfExists('record_classifications');
    }
};
