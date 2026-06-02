<?php

namespace App\Http\Controllers;

use App\Http\Resources\AudioJobResource;
use App\Models\AudioJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AudioJobController extends Controller
{
    #[OA\Get(
        path: '/api/jobs/{jobId}',
        summary: 'Poll a queued analysis job by ID',
        description: 'Returns the current job status. When status is "completed", audio_file contains the full analysis result.',
        tags: ['Jobs'],
        parameters: [
            new OA\Parameter(
                name: 'jobId',
                description: 'UUID returned from POST /api/upload when count >= 10',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', example: '550e8400-e29b-41d4-a716-446655440000'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Job status (queued / processing / completed / failed)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'job_id', type: 'string'),
                                new OA\Property(property: 'status', type: 'string', enum: ['queued', 'processing', 'completed', 'failed']),
                                new OA\Property(property: 'error', type: 'string', nullable: true),
                                new OA\Property(property: 'audio_file', ref: '#/components/schemas/AudioFileResponse', nullable: true),
                            ],
                            type: 'object',
                        ),
                    ],
                ),
            ),
            new OA\Response(
                response: 404,
                description: 'Job not found',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Job not found.'),
                    ],
                ),
            ),
        ],
    )]
    public function show(Request $request, string $jobId): JsonResponse
    {
        $job = AudioJob::find($jobId);

        if (!$job) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        $resource = new AudioJobResource($job);
        return response()->json(['data' => $resource->toArray($request)], 200);
    }
}
