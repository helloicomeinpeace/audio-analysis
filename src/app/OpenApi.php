<?php

namespace App;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Audio Analysis Service API',
    description: 'Upload .mp3 voice notes and receive duration, quality score, outlier flag, and duplicate-detection metadata. Below 10 total uploads the analysis is synchronous; at 10+ uploads it is queued and a job_id is returned for polling.',
)]
#[OA\Server(
    url: 'http://localhost:8080',
    description: 'Local Docker',
)]
#[OA\Tag(
    name: 'Audio',
    description: 'Upload and analyze MP3 voice notes',
)]
#[OA\Tag(
    name: 'Jobs',
    description: 'Poll for background analysis results',
)]
class OpenApi
{
}
