<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('outbox.connection'))->create(config('outbox.table'), function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id');
            $table->string('event');
            $table->string('subscriber');
            $table->text('url');
            $table->longText('payload');
            $table->json('log_context');
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('enqueued_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'subscriber']);
            $table->index('status');
            $table->index('created_at');
            $table->index('enqueued_at');
        });
    }

    public function down(): void
    {
        Schema::connection(config('outbox.connection'))->dropIfExists(config('outbox.table'));
    }
};
