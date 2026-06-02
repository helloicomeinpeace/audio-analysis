<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadAudioRequest;
use App\Http\Resources\AudioFileResource;
use App\Http\Resources\AudioJobResource;
use App\Jobs\AnalyzeAudioJob;
use App\Models\AudioFile;
use App\Models\AudioJob;
use App\Services\AudioAnalysisService;
use App\Services\DuplicateDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class AudioController extends Controller
{
    public const ASYNC_THRESHOLD = 10;

    public function __construct(
        private readonly AudioAnalysisService $analysis,
        private readonly DuplicateDetectionService $duplicates,
    ) {
    }

    #[OA\Post(
        path: '/api/upload',
        summary: 'Upload an MP3 voice note for analysis',
        description: 'Returns duration, quality score, outlier flag, and duplicate-detection info. Below 10 total uploads the analysis is synchronous (201). At 10 or more, the analysis is queued (202) and a job_id is returned for polling at /api/jobs/{jobId}.',
        tags: ['Audio'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['audio'],
                    properties: [
                        new OA\Property(
                            property: 'audio',
                            description: 'MP3 file (max 10 MB)',
                            type: 'string',
                            format: 'binary',
                        ),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'New upload, synchronous analysis complete',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            ref: '#/components/schemas/AudioFileResponse',
                        ),
                    ],
                ),
            ),
            new OA\Response(
                response: 202,
                description: 'New upload, queued for background analysis',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'job_id', type: 'string', example: '550e8400-e29b-41d4-a716-446655440000'),
                                new OA\Property(property: 'status', type: 'string', example: 'queued'),
                                new OA\Property(property: 'status_url', type: 'string', example: '/api/jobs/550e8400-e29b-41d4-a716-446655440000'),
                                new OA\Property(
                                    property: 'duplicate',
                                    properties: [
                                        new OA\Property(property: 'is_duplicate', type: 'boolean', example: false),
                                        new OA\Property(property: 'original_upload_id', type: 'string', nullable: true, example: null),
                                    ],
                                    type: 'object',
                                ),
                            ],
                            type: 'object',
                        ),
                    ],
                ),
            ),
            new OA\Response(
                response: 200,
                description: 'Duplicate detected, returns the original analysis',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/AudioFileResponse'),
                    ],
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error (missing field, not an MP3, or over 10 MB)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'The audio field must be a valid MP3 file.'),
                        new OA\Property(
                            property: 'errors',
                            properties: [
                                new OA\Property(
                                    property: 'audio',
                                    type: 'array',
                                    items: new OA\Items(type: 'string'),
                                ),
                            ],
                            type: 'object',
                        ),
                    ],
                ),
            ),
        ],
    )]
    public function upload(UploadAudioRequest $request): JsonResponse
    {
        $file = $request->file('audio');
        $hash = $this->duplicates->hash($file->getRealPath());

        $existing = $this->duplicates->findByHash($hash);
        if ($existing) {
            $resource = (new AudioFileResource($existing))
                ->withDuplicateInfo(true, (string) $existing->_id);
            return response()->json(['data' => $resource->toArray($request)], 200);
        }

        $storedPath = $file->store('uploads', 'local');
        $sizeBytes = $file->getSize();
        $originalName = $file->getClientOriginalName();

        $count = AudioFile::count();
        if ($count < self::ASYNC_THRESHOLD) {
            return $this->processSync($storedPath, $originalName, $hash, $sizeBytes, $request);
        }

        return $this->processAsync($storedPath, $originalName, $hash, $sizeBytes, $request);
    }

    private function processSync(string $storedPath, string $originalName, string $hash, int $sizeBytes, $request): JsonResponse
    {
        $absolutePath = Storage::disk('local')->path($storedPath);
        $metadata = $this->analysis->extractMetadata($absolutePath);

        $audioFile = AudioFile::create([
            'original_filename' => $originalName,
            'stored_path' => $storedPath,
            'file_hash' => $hash,
            'duration_seconds' => $metadata['duration_seconds'],
            'is_duration_outlier' => $this->analysis->isOutlier($metadata['duration_seconds']),
            'quality_score' => $this->analysis->qualityScore($metadata),
            'bitrate_kbps' => $metadata['bitrate_kbps'],
            'bitrate_mode' => $metadata['bitrate_mode'],
            'sample_rate_hz' => $metadata['sample_rate_hz'],
            'channels' => $metadata['channels'],
            'channel_mode' => $metadata['channel_mode'],
            'lowpass_hz' => $metadata['lowpass_hz'],
            'encoder' => $metadata['encoder'],
            'vbr_quality' => $metadata['vbr_quality'],
            'file_size_bytes' => $sizeBytes,
        ]);

        $resource = (new AudioFileResource($audioFile))->withDuplicateInfo(false);
        return response()->json(['data' => $resource->toArray($request)], 201);
    }

    private function processAsync(string $storedPath, string $originalName, string $hash, int $sizeBytes, $request): JsonResponse
    {
        $job = AudioJob::create([
            '_id' => (string) Str::uuid(),
            'status' => AudioJob::STATUS_QUEUED,
            'audio_file_id' => null,
            'error' => null,
        ]);

        AnalyzeAudioJob::dispatch(
            (string) $job->_id,
            $storedPath,
            $originalName,
            $hash,
            $sizeBytes,
        );

        $resource = (new AudioJobResource($job))->withStatusUrl()->withDuplicateBlock();
        return response()->json(['data' => $resource->toArray($request)], 202);
    }
}
