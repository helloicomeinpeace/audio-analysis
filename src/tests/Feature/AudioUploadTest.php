<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeAudioJob;
use App\Models\AudioFile;
use App\Models\AudioJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AudioUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AudioFile::truncate();
        AudioJob::truncate();
        Storage::fake('local');
    }

    public function test_upload_rejects_non_mp3_files(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->postJson('/api/upload', ['audio' => $file]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('audio');
    }

    public function test_upload_rejects_files_over_10mb(): void
    {
        $file = UploadedFile::fake()->create('big.mp3', 11000, 'audio/mpeg');

        $response = $this->postJson('/api/upload', ['audio' => $file]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('audio');
    }

    public function test_upload_requires_audio_field(): void
    {
        $response = $this->postJson('/api/upload', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('audio');
    }

    public function test_duplicate_upload_returns_existing_record(): void
    {
        $existing = AudioFile::create([
            'original_filename' => 'original.mp3',
            'stored_path' => 'uploads/orig.mp3',
            'file_hash' => hash('sha256', 'identical-content'),
            'duration_seconds' => 45.0,
            'is_duration_outlier' => false,
            'quality_score' => 6,
            'bitrate_kbps' => 128,
            'bitrate_mode' => 'cbr',
            'sample_rate_hz' => 44100,
            'channels' => 1,
            'channel_mode' => 'mono',
            'lowpass_hz' => 16000,
            'encoder' => 'LAME',
            'vbr_quality' => null,
            'file_size_bytes' => 720000,
        ]);

        $file = UploadedFile::fake()->createWithContent('new.mp3', 'identical-content');

        $response = $this->postJson('/api/upload', ['audio' => $file]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.duplicate.is_duplicate', true);
        $response->assertJsonPath('data.duplicate.original_upload_id', (string) $existing->_id);
    }

    public function test_upload_queues_job_when_at_or_above_threshold(): void
    {
        Queue::fake();

        for ($i = 1; $i <= 10; $i++) {
            AudioFile::create([
                'original_filename' => "f{$i}.mp3",
                'stored_path' => "uploads/f{$i}.mp3",
                'file_hash' => str_pad((string) $i, 64, '0', STR_PAD_LEFT),
                'duration_seconds' => 30.0 + $i,
                'is_duration_outlier' => false,
                'quality_score' => 5,
                'bitrate_kbps' => 128,
                'bitrate_mode' => 'cbr',
                'sample_rate_hz' => 44100,
                'channels' => 1,
                'channel_mode' => 'mono',
                'lowpass_hz' => 16000,
                'encoder' => 'LAME',
                'vbr_quality' => null,
                'file_size_bytes' => 100000,
            ]);
        }

        $file = UploadedFile::fake()->createWithContent('new.mp3', 'fresh-content-' . uniqid());

        $response = $this->postJson('/api/upload', ['audio' => $file]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['data' => ['job_id', 'status', 'status_url', 'duplicate']]);
        $response->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(AnalyzeAudioJob::class);
    }
}
