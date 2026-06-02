<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class AudioFile extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'audio_files';

    protected $fillable = [
        'original_filename',
        'stored_path',
        'file_hash',
        'duration_seconds',
        'is_duration_outlier',
        'quality_score',
        'bitrate_kbps',
        'bitrate_mode',
        'sample_rate_hz',
        'channels',
        'channel_mode',
        'lowpass_hz',
        'encoder',
        'vbr_quality',
        'file_size_bytes',
    ];

    protected $casts = [
        'duration_seconds' => 'float',
        'is_duration_outlier' => 'boolean',
        'quality_score' => 'integer',
        'bitrate_kbps' => 'integer',
        'sample_rate_hz' => 'integer',
        'channels' => 'integer',
        'lowpass_hz' => 'integer',
        'vbr_quality' => 'integer',
        'file_size_bytes' => 'integer',
    ];
}
