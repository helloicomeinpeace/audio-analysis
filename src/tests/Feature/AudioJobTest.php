<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\AudioJob;
use Illuminate\Support\Str;
use Tests\TestCase;

class AudioJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AudioFile::truncate();
        AudioJob::truncate();
    }

    public function test_show_returns_404_for_unknown_job(): void
    {
        $response = $this->getJson('/api/jobs/nonexistent-id');
        $response->assertStatus(404);
    }

    public function test_show_returns_queued_status(): void
    {
        $job = AudioJob::create([
            '_id' => (string) Str::uuid(),
            'status' => AudioJob::STATUS_QUEUED,
            'audio_file_id' => null,
            'error' => null,
        ]);

        $response = $this->getJson('/api/jobs/' . $job->_id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'queued');
        $response->assertJsonPath('data.audio_file', null);
    }

    public function test_show_returns_completed_status_with_audio_file(): void
    {
        $audio = AudioFile::create([
            'original_filename' => 'done.mp3',
            'stored_path' => 'uploads/done.mp3',
            'file_hash' => str_repeat('a', 64),
            'duration_seconds' => 47.2,
            'is_duration_outlier' => false,
            'quality_score' => 5,
            'bitrate_kbps' => 128,
            'bitrate_mode' => 'cbr',
            'sample_rate_hz' => 44100,
            'channels' => 1,
            'channel_mode' => 'mono',
            'lowpass_hz' => 16000,
            'encoder' => 'LAME 3.99.5',
            'vbr_quality' => null,
            'file_size_bytes' => 753664,
        ]);

        $job = AudioJob::create([
            '_id' => (string) Str::uuid(),
            'status' => AudioJob::STATUS_COMPLETED,
            'audio_file_id' => (string) $audio->_id,
            'error' => null,
        ]);

        $response = $this->getJson('/api/jobs/' . $job->_id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'completed');
        $response->assertJsonPath('data.audio_file.original_filename', 'done.mp3');
        $response->assertJsonPath('data.audio_file.duration.seconds', 47.2);
        $response->assertJsonPath('data.audio_file.quality_score', 5);
    }

    public function test_show_returns_failed_status_with_error(): void
    {
        $job = AudioJob::create([
            '_id' => (string) Str::uuid(),
            'status' => AudioJob::STATUS_FAILED,
            'audio_file_id' => null,
            'error' => 'Unable to read audio metadata.',
        ]);

        $response = $this->getJson('/api/jobs/' . $job->_id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'failed');
        $response->assertJsonPath('data.error', 'Unable to read audio metadata.');
    }
}
