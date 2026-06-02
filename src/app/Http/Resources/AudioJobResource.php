<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AudioJobResource extends JsonResource
{
    public bool $includeStatusUrl = false;
    public bool $includeDuplicate = false;

    public function withStatusUrl(): self
    {
        $this->includeStatusUrl = true;
        return $this;
    }

    public function withDuplicateBlock(): self
    {
        $this->includeDuplicate = true;
        return $this;
    }

    public function toArray(Request $request): array
    {
        $payload = [
            'job_id' => (string) $this->_id,
            'status' => $this->status,
        ];

        if ($this->includeStatusUrl) {
            $payload['status_url'] = '/api/jobs/' . $this->_id;
        }

        if ($this->status === 'failed') {
            $payload['error'] = $this->error;
        }

        if ($this->status === 'completed' && $this->audio_file_id) {
            $audio = \App\Models\AudioFile::find($this->audio_file_id);
            $payload['audio_file'] = $audio
                ? (new AudioFileResource($audio))->withDuplicateInfo(false)->toArray($request)
                : null;
        } else {
            $payload['audio_file'] = null;
        }

        if ($this->includeDuplicate) {
            $payload['duplicate'] = [
                'is_duplicate' => false,
                'original_upload_id' => null,
            ];
        }

        return $payload;
    }
}
