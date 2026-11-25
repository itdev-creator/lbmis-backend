<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);                 // RM per billing period
            $table->string('billing_period', 10)->default('monthly'); // monthly | yearly
            $table->json('modules');                          // keys from config('lbmis.modules')
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('franchisee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending'); // App\Enums\SubscriptionStatus
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('source', 20)->default('manual');  // manual | gkash
            $table->string('reference')->nullable();          // payment reference, later from GKash
            $table->timestamps();

            $table->index(['franchisee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
