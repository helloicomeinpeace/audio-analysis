<?php

namespace Tests\Unit;

use App\Models\AudioFile;
use App\Services\AudioAnalysisService;
use Tests\TestCase;

class AudioAnalysisServiceTest extends TestCase
{
    private AudioAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AudioAnalysisService();
        AudioFile::truncate();
    }

    public function test_quality_score_returns_10_for_pristine_vbr_v0_stereo(): void
    {
        $metadata = [
            'bitrate_kbps' => 245,
            'bitrate_mode' => 'vbr',
            'sample_rate_hz' => 44100,
            'channels' => 2,
            'channel_mode' => 'joint stereo',
            'lowpass_hz' => 19500,
            'encoder' => 'LAME 3.99.5',
            'vbr_quality' => 0,
        ];

        $this->assertSame(10, $this->service->qualityScore($metadata));
    }

    public function test_quality_score_returns_5_for_typical_voice_note(): void
    {
        $metadata = [
            'bitrate_kbps' => 128,
            'bitrate_mode' => 'cbr',
            'sample_rate_hz' => 44100,
            'channels' => 1,
            'channel_mode' => 'mono',
            'lowpass_hz' => 16000,
            'encoder' => 'LAME 3.99.5',
            'vbr_quality' => null,
        ];

        $this->assertSame(5, $this->service->qualityScore($metadata));
    }

    public function test_quality_score_returns_2_for_low_quality_mono(): void
    {
        $metadata = [
            'bitrate_kbps' => 64,
            'bitrate_mode' => 'cbr',
            'sample_rate_hz' => 22050,
            'channels' => 1,
            'channel_mode' => 'mono',
            'lowpass_hz' => 12000,
            'encoder' => 'LAME 3.99.5',
            'vbr_quality' => null,
        ];

        $this->assertSame(2, $this->service->qualityScore($metadata));
    }

    public function test_quality_score_minimum_is_1(): void
    {
        $metadata = [
            'bitrate_kbps' => 32,
            'bitrate_mode' => 'cbr',
            'sample_rate_hz' => 8000,
            'channels' => 1,
            'channel_mode' => 'mono',
            'lowpass_hz' => 4000,
            'encoder' => null,
            'vbr_quality' => null,
        ];

        $this->assertSame(1, $this->service->qualityScore($metadata));
    }

    public function test_is_outlier_uses_fixed_thresholds_when_below_10_records(): void
    {
        $this->assertTrue($this->service->isOutlier(1.0));
        $this->assertTrue($this->service->isOutlier(200.0));
        $this->assertFalse($this->service->isOutlier(45.0));
        $this->assertFalse($this->service->isOutlier(2.0));
        $this->assertFalse($this->service->isOutlier(180.0));
    }

    public function test_is_outlier_uses_log_iqr_when_at_or_above_10_records(): void
    {
        $durations = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];
        foreach ($durations as $d) {
            AudioFile::create([
                'original_filename' => "f{$d}.mp3",
                'stored_path' => "uploads/f{$d}.mp3",
                'file_hash' => str_pad((string) $d, 64, '0', STR_PAD_LEFT),
                'duration_seconds' => $d,
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

        $this->assertFalse($this->service->isOutlier(55.0));
        $this->assertTrue($this->service->isOutlier(0.5));
    }

    public function test_format_duration(): void
    {
        $this->assertSame('0:47', $this->service->formatDuration(47.2));
        $this->assertSame('3:00', $this->service->formatDuration(180.0));
        $this->assertSame('1:05', $this->service->formatDuration(65.4));
        $this->assertSame('0:02', $this->service->formatDuration(2.0));
    }
}
