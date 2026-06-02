<?php

namespace App\Jobs;

use App\Models\AudioFile;
use App\Models\AudioJob;
use App\Services\AudioAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AnalyzeAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public string $jobId,
        public string $storedPath,
        public string $originalFilename,
        public string $fileHash,
        public int $fileSizeBytes,
    ) {
    }

    public function handle(AudioAnalysisService $analysis): void
    {
        $job = AudioJob::find($this->jobId);
        if (!$job) {
            return;
        }

        $job->update(['status' => AudioJob::STATUS_PROCESSING]);

        try {
            $absolutePath = Storage::disk('local')->path($this->storedPath);
            $metadata = $analysis->extractMetadata($absolutePath);

            $audioFile = AudioFile::create([
                'original_filename' => $this->originalFilename,
                'stored_path' => $this->storedPath,
                'file_hash' => $this->fileHash,
                'duration_seconds' => $metadata['duration_seconds'],
                'is_duration_outlier' => $analysis->isOutlier($metadata['duration_seconds']),
                'quality_score' => $analysis->qualityScore($metadata),
                'bitrate_kbps' => $metadata['bitrate_kbps'],
                'bitrate_mode' => $metadata['bitrate_mode'],
                'sample_rate_hz' => $metadata['sample_rate_hz'],
                'channels' => $metadata['channels'],
                'channel_mode' => $metadata['channel_mode'],
                'lowpass_hz' => $metadata['lowpass_hz'],
                'encoder' => $metadata['encoder'],
                'vbr_quality' => $metadata['vbr_quality'],
                'file_size_bytes' => $this->fileSizeBytes,
            ]);

            $job->update([
                'status' => AudioJob::STATUS_COMPLETED,
                'audio_file_id' => (string) $audioFile->_id,
            ]);
        } catch (Throwable $e) {
            $job->update([
                'status' => AudioJob::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $job = AudioJob::find($this->jobId);
        if ($job && $job->status !== AudioJob::STATUS_FAILED) {
            $job->update([
                'status' => AudioJob::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
