<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event', 32);
            $table->string('auditable_type', 191);
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('summary', 255);
            $table->json('changed_fields')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['organization_id', 'created_at'], 'audit_logs_org_created_idx');
            $table->index(['organization_id', 'branch_id', 'created_at'], 'audit_logs_org_branch_created_idx');
            $table->index(['auditable_type', 'auditable_id'], 'audit_logs_entity_idx');
            $table->index(['actor_id', 'created_at'], 'audit_logs_actor_created_idx');
            $table->index(['organization_id', 'event', 'created_at'], 'audit_logs_org_event_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
