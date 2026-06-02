<?php

namespace App\Services;

use App\Models\AudioFile;

class DuplicateDetectionService
{
    public function hash(string $filePath): string
    {
        return hash_file('sha256', $filePath);
    }

    public function findByHash(string $hash): ?AudioFile
    {
        return AudioFile::where('file_hash', $hash)->first();
    }
}
