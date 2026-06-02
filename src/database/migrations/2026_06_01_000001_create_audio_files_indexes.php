<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('audio_files', function (Blueprint $collection) {
            $collection->unique('file_hash');
            $collection->index('duration_seconds');
            $collection->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->drop('audio_files');
    }
};
