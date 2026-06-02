<?php

namespace App\Services;

use App\Models\AudioFile;
use getID3;
use RuntimeException;

class AudioAnalysisService
{
    public const VOICE_NOTE_MIN_SECONDS = 2;
    public const VOICE_NOTE_MAX_SECONDS = 180;
    public const IQR_SAMPLE_MIN = 10;

    public function extractMetadata(string $filePath): array
    {
        $getId3 = new getID3();
        $info = $getId3->analyze($filePath);

        if (!empty($info['error'])) {
            throw new RuntimeException('Unable to read audio metadata: ' . implode('; ', $info['error']));
        }

        $duration = (float) ($info['playtime_seconds'] ?? 0.0);
        $bitrateKbps = (int) round(($info['audio']['bitrate'] ?? 0) / 1000);
        $bitrateMode = $info['audio']['bitrate_mode'] ?? 'cbr';
        $sampleRate = (int) ($info['audio']['sample_rate'] ?? 0);
        $channels = (int) ($info['audio']['channels'] ?? 1);
        $channelMode = $info['audio']['channelmode'] ?? ($channels === 1 ? 'mono' : 'stereo');
        $encoder = $info['audio']['encoder'] ?? null;
        $lowpassHz = isset($info['mpeg']['audio']['LAME']['lowpass_filter'])
            ? (int) $info['mpeg']['audio']['LAME']['lowpass_filter']
            : null;
        // getID3 exposes the LAME tag's quality byte (offset 0x9B) on a 0-100 scale,
        // computed as Quality = 100 - 10*VBR_q - quality. Convert it back to the
        // familiar LAME V-scale (0-9) the same way getID3 does internally.
        $rawVbrQuality = isset($info['mpeg']['audio']['LAME']['vbr_quality'])
            ? (int) $info['mpeg']['audio']['LAME']['vbr_quality']
            : null;
        $vbrQuality = ($bitrateMode === 'vbr' && $rawVbrQuality !== null && $rawVbrQuality >= 0 && $rawVbrQuality <= 100)
            ? max(0, min(9, 10 - (int) ceil($rawVbrQuality / 10)))
            : null;

        return [
            'duration_seconds' => $duration,
            'bitrate_kbps' => $bitrateKbps,
            'bitrate_mode' => $bitrateMode,
            'sample_rate_hz' => $sampleRate,
            'channels' => $channels,
            'channel_mode' => $channelMode,
            'lowpass_hz' => $lowpassHz,
            'encoder' => $encoder,
            'vbr_quality' => $vbrQuality,
        ];
    }

    public function isOutlier(float $seconds): bool
    {
        $count = AudioFile::count();

        if ($count < self::IQR_SAMPLE_MIN) {
            return $seconds < self::VOICE_NOTE_MIN_SECONDS || $seconds > self::VOICE_NOTE_MAX_SECONDS;
        }

        return $this->isOutlierByLogIQR($seconds);
    }

    private function isOutlierByLogIQR(float $seconds): bool
    {
        $durations = AudioFile::pluck('duration_seconds')->all();

        $logDurations = array_map(fn ($d) => log(max((float) $d, 0.001)), $durations);
        sort($logDurations);

        $q1 = $this->percentile($logDurations, 0.25);
        $q3 = $this->percentile($logDurations, 0.75);
        $iqr = $q3 - $q1;

        $logVal = log(max($seconds, 0.001));

        return $logVal < ($q1 - 1.5 * $iqr) || $logVal > ($q3 + 1.5 * $iqr);
    }

    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return (float) $sorted[0];
        }

        $idx = $p * ($n - 1);
        $lower = (int) floor($idx);
        $upper = (int) ceil($idx);

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($idx - $lower);
    }

    public function qualityScore(array $metadata): int
    {
        $score = $this->scoreBitrate($metadata['bitrate_kbps'], $metadata['bitrate_mode'], $metadata['vbr_quality'])
            + $this->scoreLowpass($metadata['lowpass_hz'], $metadata['sample_rate_hz'])
            + $this->scoreSampleRate($metadata['sample_rate_hz'])
            + $this->scoreBitrateMode($metadata['bitrate_mode'])
            + $this->scoreChannelMode($metadata['channel_mode']);

        return max(1, min(10, $score));
    }

    private function scoreBitrate(int $kbps, string $mode, ?int $vbrQuality): int
    {
        if ($mode === 'vbr' && $vbrQuality !== null) {
            return match (true) {
                $vbrQuality <= 1 => 4,
                $vbrQuality <= 3 => 3,
                $vbrQuality <= 5 => 2,
                $vbrQuality <= 7 => 1,
                default => 0,
            };
        }

        return match (true) {
            $kbps >= 256 => 4,
            $kbps >= 192 => 3,
            $kbps >= 128 => 2,
            $kbps >= 64 => 1,
            default => 0,
        };
    }

    private function scoreLowpass(?int $lowpassHz, int $sampleRate): int
    {
        $cutoff = $lowpassHz ?? (int) ($sampleRate / 2 * 0.9);

        return match (true) {
            $cutoff >= 19500 => 2,
            $cutoff >= 16000 => 1,
            default => 0,
        };
    }

    private function scoreSampleRate(int $hz): int
    {
        return match (true) {
            $hz >= 44100 => 2,
            $hz >= 22050 => 1,
            default => 0,
        };
    }

    private function scoreBitrateMode(string $mode): int
    {
        return $mode === 'vbr' ? 1 : 0;
    }

    private function scoreChannelMode(string $mode): int
    {
        return in_array($mode, ['stereo', 'joint stereo'], true) ? 1 : 0;
    }

    public function formatDuration(float $seconds): string
    {
        $minutes = (int) floor($seconds / 60);
        $secs = (int) floor($seconds % 60);

        return sprintf('%d:%02d', $minutes, $secs);
    }
}
