<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A limited-time offer on a plan (docs/BILLING.md): a lower price per interval until a moment. A workspace that takes
 * the plan meanwhile keeps that price, as it keeps any price it took a plan at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            // Minor units, like the prices; null = no offer for that interval.
            $table->unsignedBigInteger('offer_monthly_amount')->nullable()->after('yearly_amount');
            $table->unsignedBigInteger('offer_yearly_amount')->nullable()->after('offer_monthly_amount');
            $table->timestamp('offer_ends_at')->nullable()->after('offer_yearly_amount');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['offer_monthly_amount', 'offer_yearly_amount', 'offer_ends_at']);
        });
    }
};
