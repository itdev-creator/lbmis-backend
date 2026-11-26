<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('cart_id', 50)->unique();          // our reference, sent to GKash as v_cartid
            $table->string('po_id', 50)->nullable()->index(); // GKash's reference (POID)
            $table->string('purpose', 20);                    // App\Enums\PaymentPurpose
            $table->foreignId('franchisee_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who started it
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('MYR');
            $table->string('status', 20)->default('pending')->index(); // App\Enums\PaymentStatus
            $table->string('gateway_status', 50)->nullable();          // e.g. "88 - Transferred"
            $table->string('payment_type', 100)->nullable();           // e.g. "Visa Credit 0011"
            $table->string('description')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->decimal('refunded_amount', 10, 2)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('gateway_response')->nullable();               // last result from GKash, for support
            $table->timestamps();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->text('gkash_epkey')->nullable()->after('reference'); // card token for renewals, encrypted
            $table->boolean('auto_renew')->default(false)->after('gkash_epkey');
            $table->unsignedSmallInteger('renewal_attempts')->default(0)->after('auto_renew');
            $table->string('last_renewal_error')->nullable()->after('renewal_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['gkash_epkey', 'auto_renew', 'renewal_attempts', 'last_renewal_error']);
        });

        Schema::dropIfExists('payments');
    }
};
