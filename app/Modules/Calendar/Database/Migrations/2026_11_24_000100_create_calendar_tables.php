<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_calendars', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Null for a workspace calendar; otherwise the member whose personal calendar this is.
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->string('color', 20);
            $table->string('visibility', 10)->default('private');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['tenant_id', 'owner_id', 'is_primary']);
        });

        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('calendar_id')->constrained('calendar_calendars')->cascadeOnDelete();
            // An exception to a recurring series: the series it belongs to and the occurrence (UTC) it replaces.
            $table->foreignUlid('recurring_event_id')->nullable()->constrained('calendar_events')->cascadeOnDelete();
            $table->dateTime('original_start')->nullable();
            $table->string('status', 10)->default('confirmed');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('location', 255)->nullable();
            $table->boolean('is_all_day')->default(false);
            // UTC instants for a timed event; floating dates at 00:00 for an all-day one (end exclusive).
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64);
            $table->json('recurrence')->nullable();
            // UTC upper bound of the series' occurrence starts; null while it repeats for ever.
            $table->dateTime('recurrence_until')->nullable();
            $table->string('color', 20)->nullable();
            $table->string('show_as', 10)->default('busy');
            $table->string('visibility', 10)->default('default');
            $table->json('reminders')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'calendar_id', 'starts_at']);
            $table->index(['tenant_id', 'calendar_id', 'recurrence_until']);
            $table->index(['tenant_id', 'starts_at']);
            $table->unique(['tenant_id', 'recurring_event_id', 'original_start'], 'calendar_events_exception_unique');
        });

        Schema::create('calendar_event_attendees', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->string('response', 20)->default('needs_action');
            $table->dateTime('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'event_id', 'membership_id']);
            $table->index(['tenant_id', 'membership_id']);
        });

        Schema::create('calendar_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->string('timezone', 64)->nullable();
            $table->unsignedTinyInteger('week_starts_on')->default(0);
            $table->string('time_format', 4)->default('12h');
            $table->string('default_view', 12)->default('week');
            $table->unsignedSmallInteger('default_duration')->default(60);
            $table->unsignedInteger('default_reminder')->nullable();
            $table->boolean('show_declined')->default(true);
            $table->json('hidden_calendar_ids')->nullable();
            $table->json('followed_member_ids')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'membership_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_preferences');
        Schema::dropIfExists('calendar_event_attendees');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('calendar_calendars');
    }
};
