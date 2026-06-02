<?php

namespace App\Http\Resources;

use App\Services\AudioAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AudioFileResponse',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '683d2a1f4e3b2c0012ab1234'),
        new OA\Property(property: 'original_filename', type: 'string', example: 'note.mp3'),
        new OA\Property(
            property: 'duration',
            properties: [
                new OA\Property(property: 'seconds', type: 'number', format: 'float', example: 47.2),
                new OA\Property(property: 'formatted', type: 'string', example: '0:47'),
                new OA\Property(property: 'is_outlier', type: 'boolean', example: false),
            ],
            type: 'object',
        ),
        new OA\Property(property: 'quality_score', type: 'integer', minimum: 1, maximum: 10, example: 5),
        new OA\Property(
            property: 'quality_details',
            properties: [
                new OA\Property(property: 'bitrate_kbps', type: 'integer', example: 128),
                new OA\Property(property: 'bitrate_mode', type: 'string', example: 'cbr', enum: ['cbr', 'vbr', 'abr']),
                new OA\Property(property: 'sample_rate_hz', type: 'integer', example: 44100),
                new OA\Property(property: 'channels', type: 'integer', example: 1),
                new OA\Property(property: 'channel_mode', type: 'string', example: 'mono'),
                new OA\Property(property: 'lowpass_hz', type: 'integer', nullable: true, example: 16000),
                new OA\Property(property: 'encoder', type: 'string', nullable: true, example: 'LAME 3.99.5'),
                new OA\Property(property: 'vbr_quality', type: 'integer', nullable: true, example: null),
            ],
            type: 'object',
        ),
        new OA\Property(
            property: 'duplicate',
            properties: [
                new OA\Property(property: 'is_duplicate', type: 'boolean', example: false),
                new OA\Property(property: 'original_upload_id', type: 'string', nullable: true, example: null),
            ],
            type: 'object',
        ),
        new OA\Property(property: 'file_size_bytes', type: 'integer', example: 753664),
        new OA\Property(property: 'uploaded_at', type: 'string', format: 'date-time', example: '2026-06-02T12:00:00Z'),
    ],
    type: 'object',
)]
class AudioFileResource extends JsonResource
{
    public ?bool $isDuplicate = null;
    public ?string $originalUploadId = null;

    public function withDuplicateInfo(bool $isDuplicate, ?string $originalUploadId = null): self
    {
        $this->isDuplicate = $isDuplicate;
        $this->originalUploadId = $originalUploadId;
        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var AudioAnalysisService $analysis */
        $analysis = app(AudioAnalysisService::class);
        $duration = (float) $this->duration_seconds;

        return [
            'id' => (string) $this->_id,
            'original_filename' => $this->original_filename,
            'duration' => [
                'seconds' => $duration,
                'formatted' => $analysis->formatDuration($duration),
                'is_outlier' => (bool) $this->is_duration_outlier,
            ],
            'quality_score' => (int) $this->quality_score,
            'quality_details' => [
                'bitrate_kbps' => (int) $this->bitrate_kbps,
                'bitrate_mode' => $this->bitrate_mode,
                'sample_rate_hz' => (int) $this->sample_rate_hz,
                'channels' => (int) $this->channels,
                'channel_mode' => $this->channel_mode,
                'lowpass_hz' => $this->lowpass_hz !== null ? (int) $this->lowpass_hz : null,
                'encoder' => $this->encoder,
                'vbr_quality' => $this->vbr_quality !== null ? (int) $this->vbr_quality : null,
            ],
            'duplicate' => [
                'is_duplicate' => $this->isDuplicate ?? false,
                'original_upload_id' => $this->originalUploadId,
            ],
            'file_size_bytes' => (int) $this->file_size_bytes,
            'uploaded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
