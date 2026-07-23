<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2.12 / BI-10: every uploaded file is tracked here; invisible until the scan passes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('context');
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('status')->default('pending'); // pending | clean | quarantined
            $table->string('scan_signature')->nullable(); // virus name on a hit
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->timestampsTz();

            $table->index(['context', 'status']);
            $table->index('status');
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
