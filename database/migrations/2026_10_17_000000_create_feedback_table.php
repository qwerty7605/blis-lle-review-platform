<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback sent from the Settings page. A problem report is emailed to the
 * developers as it arrives; an idea, a question or anything else waits for
 * the administrator, who decides whether it goes to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('message');
            $table->boolean('may_contact')->default(false);
            // Where it was sent from: the page before Settings, the device,
            // the app version and the screen size.
            $table->json('context')->nullable();
            $table->string('status', 20)->default('new');
            // Shown to the sender: the administrator's answer, or why not.
            $table->text('admin_reply')->nullable();
            // Sent to the developers with it.
            $table->text('developer_note')->nullable();
            $table->timestamp('developer_emailed_at')->nullable();
            $table->string('developer_email_error', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
