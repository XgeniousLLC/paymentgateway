<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create("xg_payment_meta", function (Blueprint $table) {
            $table->id();
            $table->string("gateway");
            $table->double("amount");
            $table->longText("meta_data")->nullable();
            $table->text("session_id")->nullable();
            $table->string("type")->nullable();
            $table->string("order_id");
            $table->string("track");
            $table
                ->unsignedBigInteger("status")
                ->default(0)
                ->comment("0=pending,1=complete,2=cancel");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists("xg_payment_meta");
    }
};
