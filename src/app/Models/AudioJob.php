<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class AudioJob extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $connection = 'mongodb';
    protected $collection = 'audio_jobs';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        '_id',
        'status',
        'audio_file_id',
        'error',
    ];

    public function audioFile()
    {
        return $this->belongsTo(AudioFile::class, 'audio_file_id');
    }
}
