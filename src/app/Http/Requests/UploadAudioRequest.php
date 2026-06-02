<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadAudioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'audio' => ['required', 'file', 'mimetypes:audio/mpeg,audio/mp3', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'audio.required' => 'An audio file is required.',
            'audio.mimetypes' => 'The audio field must be a valid MP3 file.',
            'audio.max' => 'The audio file must not be larger than 10 MB.',
        ];
    }
}
