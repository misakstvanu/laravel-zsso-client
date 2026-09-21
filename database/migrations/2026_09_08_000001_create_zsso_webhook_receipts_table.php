<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per webhook this app has received (contract C-7). The envelope
     * id is the primary key: it is what makes a redelivery a no-op.
     */
    public function up(): void
    {
        Schema::create('zsso_webhook_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zsso_webhook_receipts');
    }
};
