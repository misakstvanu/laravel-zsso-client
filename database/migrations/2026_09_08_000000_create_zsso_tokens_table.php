<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per locally signed-in user: the zSSO tokens this app acts with.
     *
     * `user_id` is a string so an app keyed by an integer and an app keyed by
     * a uuid both fit; `ZssoToken` always binds it as one.
     */
    public function up(): void
    {
        Schema::create('zsso_tokens', function (Blueprint $table) {
            $table->string('user_id')->primary();
            $table->string('sub')->index();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zsso_tokens');
    }
};
