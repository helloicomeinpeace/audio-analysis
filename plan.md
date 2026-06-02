# Audio Analysis Service — Plan

## Overview

A Laravel (PHP) REST API that accepts `.mp3` voice note uploads and returns audio metadata and analysis. Built with Docker, MongoDB, and a Laravel queue worker.

**Designed scope:** Short voice notes, 2 seconds to 3 minutes. The outlier thresholds, hashing strategy, and queue routing are tuned to this assumption.

Features:
- Audio duration with adaptive outlier detection (fixed thresholds → log-IQR at scale)
- Heuristic quality score (1–10)
- Exact-match duplicate detection via SHA-256 content hash
- Adaptive queue routing — synchronous under 10 uploads, background job at 10+
- Every upload from the 10th onward triggers a queued analysis job
- Job polling endpoint for queued results

---

## Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                        Docker Compose                        │
│                                                              │
│  ┌──────────────┐    ┌─────────────────────┐                │
│  │   webserver  │───▶│        app          │                │
│  │  nginx:alpine│    │  php:8.2-fpm-alpine │                │
│  │   port 8080  │    │  Laravel 11         │                │
│  └──────────────┘    └──────────┬──────────┘                │
│                                 │                            │
│  ┌───────────────────┐          │  ┌──────────────────────┐ │
│  │      queue        │          ├─▶│      mongodb         │ │
│  │  php artisan      │          │  │  mongo:7             │ │
│  │  queue:work       │──────────┘  │  port 27017          │ │
│  └───────────────────┘             └──────────────────────┘ │
│                                                              │
│                    ┌─────────────────────┐                  │
│                    │  storage/app/uploads│  (local volume)  │
│                    └─────────────────────┘                  │
└──────────────────────────────────────────────────────────────┘
```

Four services: `webserver` (Nginx), `app` (PHP-FPM), `queue` (Laravel worker), `mongodb`.

---

## Project Structure

```
audio-analysis/
├── plan.md                   ← this file
├── README.md                 ← setup, architecture, decisions, assumptions
├── Dockerfile
├── docker-compose.yml
├── nginx/default.conf
├── Makefile
├── .env.example
└── src/                      ← Laravel root
    ├── app/
    │   ├── Http/
    │   │   ├── Controllers/
    │   │   │   ├── AudioController.php
    │   │   │   └── AudioJobController.php    ← polling endpoint
    │   │   ├── Requests/
    │   │   │   └── UploadAudioRequest.php
    │   │   └── Resources/
    │   │       ├── AudioFileResource.php
    │   │       └── AudioJobResource.php
    │   ├── Jobs/
    │   │   └── AnalyzeAudioJob.php
    │   ├── Models/
    │   │   ├── AudioFile.php                 ← MongoDB model
    │   │   └── AudioJob.php                  ← MongoDB model
    │   └── Services/
    │       ├── AudioAnalysisService.php
    │       └── DuplicateDetectionService.php
    ├── database/migrations/                  ← index-only migrations
    │   ├── xxxx_create_audio_files_indexes.php
    │   └── xxxx_create_audio_jobs_indexes.php
    ├── routes/api.php
    └── tests/
        ├── Unit/AudioAnalysisServiceTest.php
        └── Feature/
            ├── AudioUploadTest.php
            └── AudioJobTest.php
```

---

## Docker Setup

### `Dockerfile`
- Base: `php:8.2-fpm-alpine`
- PHP extensions: `ext-mongodb` (via pecl), `fileinfo`, `mbstring`, `tokenizer`, `xml`, `ctype`
- Composer installed in image
- `composer install` at build time

### `docker-compose.yml`

| Service | Image | Role |
|---------|-------|------|
| `app` | custom Dockerfile | PHP-FPM, Laravel |
| `webserver` | `nginx:alpine` | Reverse proxy, host port 8080 |
| `queue` | same Dockerfile | `php artisan queue:work --tries=3` |
| `mongodb` | `mongo:7` | Primary datastore + queue backend |

Queue driver: `mongodb` via `mongodb/laravel-mongodb`. No Redis needed — MongoDB handles both data and job queuing.

### `Makefile`
```
make up       # build + start all four services
make down     # stop
make migrate  # create MongoDB indexes
make test     # run PHPUnit
make shell    # shell into app container
make logs     # tail all container logs
```

---

## MongoDB Collections

MongoDB is schemaless. Migrations create **indexes only**.

### `audio_files` collection

| Field | Type | Notes |
|-------|------|-------|
| `_id` | ObjectId | MongoDB primary key |
| `original_filename` | string | as uploaded |
| `stored_path` | string | relative path in storage volume |
| `file_hash` | string | SHA-256, **unique index** |
| `duration_seconds` | double | |
| `is_duration_outlier` | bool | |
| `quality_score` | int | 1–10 |
| `bitrate_kbps` | int | average/constant bitrate |
| `bitrate_mode` | string | `cbr`, `vbr`, `abr` |
| `sample_rate_hz` | int | |
| `channels` | int | 1 or 2 |
| `channel_mode` | string | `mono`, `stereo`, `joint stereo`, `dual channel` |
| `lowpass_hz` | int nullable | from LAME info tag; null if not LAME-encoded |
| `encoder` | string nullable | e.g., `LAME 3.99.5`; null if no info tag |
| `vbr_quality` | int nullable | LAME V0–V9 (0=best); null if CBR/ABR or non-LAME |
| `file_size_bytes` | long | |
| `created_at` / `updated_at` | ISODate | |

Indexes:
- `{ file_hash: 1 }` — unique, for duplicate detection
- `{ duration_seconds: 1 }` — for log-IQR outlier queries

### `audio_jobs` collection

| Field | Type | Notes |
|-------|------|-------|
| `_id` | UUID string | returned to client as `job_id` |
| `status` | string | `queued`, `processing`, `completed`, `failed` |
| `audio_file_id` | ObjectId nullable | set on completion |
| `error` | string nullable | set on failure |
| `created_at` / `updated_at` | ISODate | |

Index: `{ status: 1, created_at: 1 }` — for worker polling and monitoring.

### `queue_jobs` collection
Managed automatically by `mongodb/laravel-mongodb` queue driver.

---

## Why MongoDB

MongoDB is chosen because:
- **High-volume writes** — MongoDB's document model and write-optimised engine (WiredTiger) handle concurrent inserts without the file-level locking that SQLite imposes.
- **Last-write-wins robustness** — MongoDB's `$set` operations on individual documents are atomic at the document level. If two workers update the same job document, the last write is applied cleanly with no partial-write corruption.
- **Schemaless flexibility** — audio metadata fields can vary (e.g., some files may lack bitrate info). MongoDB stores partial documents without schema alteration.
- **Queue unification** — `mongodb/laravel-mongodb` provides a MongoDB-backed queue driver, eliminating the need for a separate Redis container.

---

## API Endpoints

### `POST /api/upload`

**Request:** `multipart/form-data`, field `audio`, `.mp3` only, max 10 MB (voice note scope).

**Upload flow:**
```
Validate → SHA-256 hash → duplicate? ──YES──▶ 200 (always sync)
                               │
                               NO
                               ▼
                         Store file
                               │
                    AudioFile::count() < 10?
                    ├── YES → analyze sync → 201 full result
                    └── NO  → create AudioJob → dispatch → 202 + job_id
```

Every upload from the 10th onward dispatches an `AnalyzeAudioJob`. Duplicate detection is always synchronous and precedes any queue decision.

**Response A — new upload, < 10 total (201):**
```json
{
  "id": "683d2a1f4e3b2c0012ab1234",
  "original_filename": "note.mp3",
  "duration": {
    "seconds": 47.2,
    "formatted": "0:47",
    "is_outlier": false
  },
  "quality_score": 5,
  "quality_details": {
    "bitrate_kbps": 128,
    "bitrate_mode": "cbr",
    "sample_rate_hz": 44100,
    "channels": 1,
    "channel_mode": "mono",
    "lowpass_hz": 16000,
    "encoder": "LAME 3.99.5",
    "vbr_quality": null
  },
  "duplicate": {
    "is_duplicate": false,
    "original_upload_id": null
  },
  "file_size_bytes": 753664,
  "uploaded_at": "2026-06-01T12:00:00Z"
}
```

**Response B — new upload, ≥ 10 total (202):**
```json
{
  "job_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "queued",
  "status_url": "/api/jobs/550e8400-e29b-41d4-a716-446655440000",
  "duplicate": { "is_duplicate": false, "original_upload_id": null }
}
```

**Response C — duplicate (200):** Full analysis of the original, `is_duplicate: true`.

---

### `GET /api/jobs/{jobId}`

**Queued / processing (200):**
```json
{ "job_id": "...", "status": "processing", "audio_file": null }
```

**Completed (200):**
```json
{ "job_id": "...", "status": "completed", "audio_file": { ...full analysis... } }
```

**Failed (200):**
```json
{ "job_id": "...", "status": "failed", "error": "Unable to read audio metadata.", "audio_file": null }
```

**Not found: 404.**

---

## Assumptions

- **Voice note scope:** The system is designed for short voice recordings ranging from **2 seconds to 3 minutes** (180 seconds). Outlier thresholds and max upload size are set accordingly.
- **Max upload size:** 10 MB (sufficient for a 3-minute MP3 at 320 kbps ≈ 7.3 MB).
- **Content hashing:** SHA-256 of the full file binary is appropriate for files of this size (2s–3min ≈ a few KB to ~7 MB). Files are read into memory in a single pass.
- **Outlier thresholds:** `< 2s` = too short to be a meaningful voice note; `> 180s` = exceeds the defined voice note scope.
- **Quality score:** Measures encoding quality, not speech clarity or acoustic quality.
- **Single MongoDB node:** No replica set configured in this take-home. A production deployment would require a 3-node replica set for high availability.

---

## Key Logic

### Adaptive Outlier Detection

**< 10 records → fixed thresholds (voice note range):**
```php
$isOutlier = $seconds < 2 || $seconds > 180;
```

**≥ 10 records → log-transform + IQR:**
```php
$logDurations = AudioFile::pluck('duration_seconds')
    ->map(fn($d) => log(max($d, 0.001)))->sort()->values()->toArray();
$q1 = $this->percentile($logDurations, 0.25);
$q3 = $this->percentile($logDurations, 0.75);
$iqr = $q3 - $q1;
$logVal = log(max($seconds, 0.001));
$isOutlier = $logVal < ($q1 - 1.5 * $iqr) || $logVal > ($q3 + 1.5 * $iqr);
```

### Duplicate Detection

SHA-256 of full file content. Appropriate for voice notes (small files). A unique index on `file_hash` in MongoDB guards against race conditions.

**Future consideration for large files (audiobooks, ebooks):** Instead of hashing the full file, chunk the content into fixed-size blocks, hash each chunk, and build a Merkle tree. The Merkle root becomes the file fingerprint. This approach avoids loading the full file into memory, supports streaming, and enables partial-duplicate detection.

### Quality Score

Five components extracted from the MP3 header via getID3, summed to a 0–10 score (minimum 1). See README.md for full scoring tables and rationale.

| Component | Weight | Source field |
|-----------|--------|-------------|
| Effective bitrate | 0–4 pts | `vbr_quality` (LAME) or `bitrate` fallback |
| Low-pass filter cutoff | 0–2 pts | `mpeg.audio.LAME.lowpass_filter` |
| Sample rate | 0–2 pts | `audio.sample_rate` |
| Bitrate mode (VBR bonus) | 0–1 pt | `audio.bitrate_mode` |
| Channel mode | 0–1 pt | `audio.channelmode` |

**getID3 fields read:**
- `audio.bitrate` — bits per second
- `audio.bitrate_mode` — `cbr`, `vbr`, `abr`
- `audio.sample_rate` — Hz
- `audio.channels` — 1 or 2
- `audio.channelmode` — `mono`, `stereo`, `joint stereo`, `dual channel`
- `audio.encoder` — encoder string (e.g., `LAME 3.99.5`)
- `mpeg.audio.LAME.lowpass_filter` — low-pass cutoff in Hz (nullable)
- `mpeg.audio.LAME.vbr_quality` — 0–9 quality preset (nullable)

### Adaptive Queue Routing

```php
if (AudioFile::count() < 10) {
    // sync: analyze inline, return 201
} else {
    // async: create AudioJob, dispatch AnalyzeAudioJob, return 202
}
```

---

## Tests

### Unit — `AudioAnalysisServiceTest`
- `qualityScore()` → 10 for VBR V0 / 44100 Hz / joint stereo / lowpass 19500 Hz
- `qualityScore()` → 2 for CBR 64 kbps / 22050 Hz / mono / lowpass 12000 Hz
- `qualityScore()` → 5 for CBR 128 kbps / 44100 Hz / mono / lowpass 16000 Hz (typical voice note)
- `qualityScore()` → 1 minimum for any valid but degraded file
- `isOutlier()` with 0 records → fixed: `true` for 1s and 200s, `false` for 45s
- `isOutlier()` with 10+ seeded records → log-IQR flags extremes
- `formatDuration()` → `"0:47"` for 47s, `"3:00"` for 180s

### Feature — `AudioUploadTest`
- Upload valid MP3 (< 10 existing) → 201 with full analysis
- Upload same file → 200, `is_duplicate: true`
- Upload non-MP3 → 422
- Upload file > 10 MB → 422

### Feature — `AudioJobTest`
- Seed 10 records → upload → 202 + `job_id`
- Poll `GET /api/jobs/{jobId}` → `queued` or `processing`
- Run `queue:work --once` → poll → `completed` with `audio_file`
- `GET /api/jobs/bad-id` → 404

---

## Trade-offs

| Decision | Trade-off |
|---------|-----------|
| MongoDB over SQL | High-write throughput, last-write-wins safety, schemaless flexibility. Heavier than SQLite; overkill for low traffic. |
| MongoDB queue driver | One less container (no Redis). Slower than Redis at very high job throughput. |
| SHA-256 full-file hash | Simple and accurate for small files. Reads entire file into memory. Not suitable for large files. |
| Fixed thresholds < 10 records | Reliable at low data volumes. Not adaptive. |
| Log-IQR ≥ 10 records | Handles right-skewed duration distributions. Queries all durations on each upload. |
| Sync below 10 / async at 10+ | Simple threshold; no extra infrastructure until needed. |

## What I'd Improve With More Time

- Cache log-IQR fence values (invalidate on each new upload) to avoid querying all durations
- Merkle-tree chunking strategy for large file deduplication
- `GET /api/uploads` list endpoint with cursor-based pagination
- Rate limiting on the upload endpoint
- MongoDB replica set in docker-compose for production-grade durability
- Streaming SHA-256 hash using `hash_update_stream`
- OpenAPI spec
