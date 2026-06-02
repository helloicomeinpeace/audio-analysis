# Audio Analysis Service

A Laravel REST API that accepts `.mp3` voice note uploads and returns audio metadata, a quality score, duration analysis, and duplicate detection. Built with PHP 8.2, Laravel 11, MongoDB, and Docker.

---

## Requirements

- [Docker](https://docs.docker.com/get-docker/) 20.10+
- [Docker Compose](https://docs.docker.com/compose/) v2+
- `make` (pre-installed on macOS/Linux)

No PHP, Composer, or MongoDB installation needed — everything runs inside Docker.

---

## Quick Start

```bash
# 1. Clone and enter the project
git clone <repo-url> audio-analysis
cd audio-analysis

# 2. Copy environment config
cp .env.example src/.env

# 3. Build and start all containers (app + nginx + queue worker + mongodb)
make up

# 4. Create MongoDB indexes
make migrate

# 5. Upload a test file
curl -X POST http://localhost:8080/api/upload \
  -F "audio=@/path/to/voice-note.mp3"
```

The API is available at `http://localhost:8080`.

---

## Makefile Commands

| Command | Description |
|---------|-------------|
| `make up` | Build images and start all containers |
| `make down` | Stop and remove containers |
| `make migrate` | Create MongoDB indexes |
| `make test` | Run the full PHPUnit test suite |
| `make shell` | Open a shell inside the app container |
| `make logs` | Tail all container logs |

---

## API

### `POST /api/upload`

Upload a voice note `.mp3` for analysis.

**Request**
```
Content-Type: multipart/form-data
Field: audio (file, .mp3, max 10 MB)
```

**Upload flow:**
```
Validate → SHA-256 hash → duplicate? ──YES──▶ 200 (always sync)
                               │
                               NO
                               ▼
                         Store file
                               │
                    AudioFile::count() < 10?
                    ├── YES → analyze sync → 201
                    └── NO  → dispatch job → 202 + job_id
```

**Response — new upload, fewer than 10 total (201)**
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

**Response — new upload, 10 or more total (202)**
```json
{
  "job_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "queued",
  "status_url": "/api/jobs/550e8400-e29b-41d4-a716-446655440000",
  "duplicate": {
    "is_duplicate": false,
    "original_upload_id": null
  }
}
```

**Response — duplicate detected (200)**

Same shape as 201, with `"is_duplicate": true` and `"original_upload_id"` set.

**Response — validation error (422)**
```json
{
  "message": "The audio field must be a file of type: mp3.",
  "errors": { "audio": ["The audio field must be a file of type: mp3."] }
}
```

---

### `GET /api/jobs/{jobId}`

Poll for a queued analysis result.

**Queued or processing (200)**
```json
{ "job_id": "...", "status": "processing", "audio_file": null }
```

**Completed (200)**
```json
{
  "job_id": "...",
  "status": "completed",
  "audio_file": { ...same shape as 201 response... }
}
```

**Failed (200)**
```json
{
  "job_id": "...",
  "status": "failed",
  "error": "Unable to read audio metadata.",
  "audio_file": null
}
```

**Not found: 404**

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

**Request flow:**
1. Nginx receives the request and forwards to PHP-FPM (port 9000)
2. `UploadAudioRequest` validates file type and size
3. `DuplicateDetectionService` computes SHA-256 and checks MongoDB for a match
4. If new: file is stored; count < 10 → sync analysis; count ≥ 10 → queued job
5. `AnalyzeAudioJob` (worker) runs `AudioAnalysisService`, updates the `AudioJob` document
6. Response shaped by `AudioFileResource` or `AudioJobResource`

**Key source files:**

| File | Responsibility |
|------|---------------|
| `app/Http/Controllers/AudioController.php` | Orchestrates upload, routes sync vs async |
| `app/Http/Controllers/AudioJobController.php` | Job status polling |
| `app/Services/AudioAnalysisService.php` | Duration, outlier detection, quality score |
| `app/Services/DuplicateDetectionService.php` | SHA-256 hashing, MongoDB lookup |
| `app/Jobs/AnalyzeAudioJob.php` | Background analysis worker |
| `app/Models/AudioFile.php` | MongoDB document model |
| `app/Models/AudioJob.php` | Job tracking document model |

---

## Running Tests

```bash
make test
```

Tests use an in-memory MongoDB instance (via `mongodb/laravel-mongodb` test helpers) and `UploadedFile::fake()`. No real MP3 files or running containers required.

**Coverage:**
- Unit: quality scoring, outlier detection (fixed + log-IQR), duration formatting
- Feature: upload flow (sync 201, async 202, duplicate 200, invalid 422), job polling

---

## Assumptions

**1. Voice note scope**
This system is designed for short voice recordings ranging from **2 seconds to 3 minutes (180 seconds)**. All thresholds, upload size limits, and hashing strategies are calibrated to this range. Behaviour with longer files (podcasts, lectures, audiobooks) is undefined — see the "Future: Large File Deduplication" section under Design Decisions.

**2. Max upload size: 10 MB**
A 3-minute MP3 at 320 kbps is approximately 7.3 MB. 10 MB gives headroom for higher bitrates while remaining tight enough to keep the service fast and focused on voice notes.

**3. Outlier thresholds: < 2s and > 180s**
Files under 2 seconds are too short to be meaningful voice notes (likely accidental recordings or corrupt files). Files over 3 minutes exceed the defined scope of the system. These bounds are fixed below 10 records; log-IQR takes over at 10+.

**4. Quality score measures encoding, not content**
The 1–10 score reflects encoding quality across five signals: effective bitrate, low-pass filter cutoff, sample rate, bitrate mode, and channel mode. All signals are read from the MP3 header — no audio decoding required. A whispered note in VBR V0 / 44100 Hz / joint stereo scores 10. A clear recording in CBR 64 kbps / 22050 Hz / mono scores low. This is intentional and documented.

**5. Single MongoDB node**
No replica set is configured in this take-home setup. A production deployment would use a 3-node replica set with `w: majority` write concern for durability.

**6. SHA-256 full-file hashing is appropriate for this scope**
At 2s–3min, MP3 files are small enough (a few KB to ~7 MB) to read into memory in a single pass. This assumption does not hold for large files — see Design Decision 2.

**7. Uploaded MP3s are LAME-encoded**
The `encoder` and `vbr_quality` fields are read from the LAME Info tag. Files carrying only a bare Xing header or a Fraunhofer VBR header don't expose these values, so both come back `null` for them (the rest of the metadata — bitrate, mode, sample rate, channels — is still read correctly). Full support for other encoders' quality/version fields was left out for time — see "What I'd Improve With More Time".

---

## Design Decisions

### 1. Audio Metadata Library — `getID3`

`james-heinrich/getid3` extracts the following fields directly from the MP3 binary — no audio decoding required:

| getID3 field | Value | Used for |
|---|---|---|
| `playtime_seconds` | duration in seconds | Duration + outlier |
| `audio.bitrate` | bits per second | Quality score |
| `audio.bitrate_mode` | `cbr`, `vbr`, `abr` | Quality score |
| `audio.sample_rate` | Hz | Quality score |
| `audio.channels` | 1 or 2 | Quality score |
| `audio.channelmode` | `mono`, `stereo`, `joint stereo` | Quality score |
| `audio.encoder` | e.g. `LAME 3.99.5` | Stored, informational |
| `mpeg.audio.LAME.lowpass_filter` | Hz (nullable) | Quality score |
| `mpeg.audio.LAME.vbr_quality` | 0–9 preset (nullable) | Quality score |

**Why:** De-facto standard PHP audio library. Pure PHP — no shell dependencies (`ffprobe`, `mediainfo`) that would complicate the Docker image or introduce shell injection risk. Actively maintained, MIT licensed.

**Alternative considered:** `ffprobe` via `shell_exec`. More accurate for edge cases but requires FFmpeg in the container and is difficult to mock in tests.

---

### 2. Duplicate Detection — SHA-256 Content Hash

```
file_hash = SHA-256( binary content of uploaded file )
```

Stored in MongoDB with a unique index on `file_hash`. On every upload, the hash is computed before the file is stored. If a match exists, the file is discarded and the original record returned. Filename is completely irrelevant.

**Why SHA-256 over MD5:** MD5 has known practical collision vulnerabilities. SHA-256 has no known collisions and is appropriate wherever hash integrity matters.

**Why a unique index:** Provides O(log n) lookup and guards against race conditions. If two identical files are uploaded simultaneously, only one insert succeeds; the second gets a duplicate key error, which the service converts to a 200 duplicate response.

**Why full-file hashing is appropriate here:** Voice notes are small (2s–3min ≈ a few KB to ~7 MB). Reading the entire file into memory for a single SHA-256 pass is fast and memory-safe within these bounds.

**Future: Large File Deduplication (Merkle Proof Strategy)**
For a system that ingests large audio files — audiobooks, ebooks, long recordings — full-file SHA-256 is impractical:
- Memory: loading a 500 MB audiobook into RAM for hashing is expensive.
- Streaming: large files should be processed as streams, not buffers.
- Partial duplicates: a re-encoded or trimmed audiobook would not match a full-file hash.

The correct approach for large files is a **chunked Merkle tree**:
1. Split the file into fixed-size chunks (e.g., 1 MB each).
2. Compute SHA-256 of each chunk.
3. Build a Merkle tree from the chunk hashes.
4. The **Merkle root** becomes the file fingerprint.

This enables:
- Streaming ingestion (no full file in memory)
- Partial-duplicate detection (matching subtrees reveal shared content)
- Efficient re-verification (only re-hash changed chunks)

For this assignment, the simple full-file SHA-256 is correct and sufficient.

---

### 3. Adaptive Outlier Detection

| Condition | Strategy |
|-----------|----------|
| `AudioFile::count() < 10` | Fixed thresholds: flag if `< 2s` or `> 180s` |
| `AudioFile::count() >= 10` | Log-transform + IQR on all stored durations |

**Why not plain IQR on a skewed distribution:**

Audio duration data is right-skewed — most voice notes cluster in the 10s–90s range, but the tail extends rightward. Applying IQR to a right-skewed distribution causes two problems:

1. **The lower fence goes negative.** `Q1 - 1.5 × IQR` on a right-skewed dataset frequently produces a meaningless negative value, failing to flag genuinely short outliers.
2. **The upper fence drifts rightward.** As longer recordings accumulate, Q3 rises and the upper fence moves out — files that were once outliers stop being flagged. The rule gets less sensitive as the dataset grows.

**Why log-transform fixes this:** Taking `log(duration)` before computing IQR compresses the right tail into near-symmetry. The quartiles and IQR become stable and meaningful on both sides, regardless of how many long-tail values accumulate.

**Why switch at 10 records:** With fewer than 10 data points, quartile computation is noisy and dominated by individual values. Fixed thresholds are more reliable and fully auditable at that scale. 10 is defined as a named constant (`IQR_SAMPLE_MIN`) so it can be changed without touching logic.

**Why these fixed thresholds (`< 2s`, `> 180s`):** Calibrated to the voice note scope assumption. Sub-2-second files are likely accidental; files over 3 minutes exceed the intended use case.

---

### 4. Quality Score (1–10)

Score = sum of five header-readable components, minimum 1. All signals are extracted by getID3 with no audio decoding.

---

#### Component 1 — Effective Bitrate (0–4 pts)

The primary quality signal. For LAME-encoded VBR files, the stored VBR quality preset (`vbr_quality` 0–9) is used — it is more accurate than average bitrate because VBR distributes bits unevenly across the file. For CBR, ABR, or files without a LAME info tag, raw bitrate is the fallback.

**VBR path (LAME `vbr_quality` tag present):**

| LAME preset | Typical avg kbps | Points | Notes |
|-------------|-----------------|--------|-------|
| V0–V1 | ~245+ kbps | 4 | Transparent; indistinguishable from lossless |
| V2–V3 | ~190 kbps | 3 | High quality; artefacts inaudible to most |
| V4–V5 | ~165 kbps | 2 | Medium quality |
| V6–V7 | ~130 kbps | 1 | Lower quality; some artefacts |
| V8–V9 | ~100 kbps | 0 | Very low quality |

**CBR / ABR / fallback path (bitrate-based):**

| kbps | Points |
|------|--------|
| ≥ 256 | 4 |
| ≥ 192 | 3 |
| ≥ 128 | 2 |
| ≥ 64  | 1 |
| < 64  | 0 |

**Why prefer the VBR quality tag over average bitrate:** VBR encodes difficult passages at higher bitrates and simple passages at lower bitrates. A VBR V0 file averaging 210 kbps is perceptually equivalent to CBR 320 kbps on most content. Scoring by average bitrate alone would undervalue it. The LAME quality tag (stored in the Xing/Info header of every LAME-encoded file) is a direct expression of the encoder's quality target.

---

#### Component 2 — Low-Pass Filter Cutoff (0–2 pts)

MP3 encoders apply a low-pass filter before encoding to discard frequencies above a cutoff — high frequencies are expensive to encode and are cut to save bits. The cutoff is written into the LAME info tag.

| Cutoff (Hz) | Points | Meaning |
|-------------|--------|---------|
| ≥ 19,500 | 2 | Full audible range; transparent |
| ≥ 16,000 | 1 | Slight high-frequency rolloff |
| < 16,000 | 0 | Audible loss of high frequencies |

If no LAME info tag is present (non-LAME encoder), the cutoff is estimated as `sample_rate / 2 × 0.9` (a conservative Nyquist approximation).

**Why this matters:** A 320 kbps CBR file with a 12 kHz low-pass filter is perceptually worse than a 192 kbps file with a 19 kHz cutoff. The low-pass filter is a direct measure of how much high-frequency content was preserved — something that bitrate alone cannot capture.

---

#### Component 3 — Sample Rate (0–2 pts)

Determines the theoretical maximum reproducible frequency (Nyquist = sample_rate / 2).

| Hz | Points | Notes |
|----|--------|-------|
| ≥ 44,100 | 2 | CD standard; 22 kHz ceiling |
| ≥ 22,050 | 1 | Half-rate; 11 kHz ceiling (FM radio) |
| < 22,050 | 0 | Telephone quality |

---

#### Component 4 — Bitrate Mode / VBR Bonus (0–1 pt)

| Mode | Points | Reasoning |
|------|--------|-----------|
| VBR | 1 | Content-adaptive; allocates bits where complexity demands them |
| CBR | 0 | Fixed allocation; wastes bits on simple passages, starves complex ones |
| ABR | 0 | Targets an average bitrate with limited variation; a compromise |

---

#### Component 5 — Channel Mode (0–1 pt)

| Mode | Points | Notes |
|------|--------|-------|
| Stereo / Joint Stereo | 1 | Full spatial information |
| Mono / Dual Channel | 0 | Single-channel; acceptable for voice notes |

**Joint Stereo vs. Stereo:** Joint stereo encodes the sum (mid) and difference (side) channels separately, allowing more efficient bit allocation. It is generally equal to or better than simple stereo at the same bitrate and is the standard mode for LAME.

---

#### Scoring Summary

| Component | Max | Signal source |
|-----------|-----|---------------|
| Effective bitrate | 4 | `vbr_quality` → `bitrate` fallback |
| Low-pass filter | 2 | `mpeg.audio.LAME.lowpass_filter` |
| Sample rate | 2 | `audio.sample_rate` |
| Bitrate mode | 1 | `audio.bitrate_mode` |
| Channel mode | 1 | `audio.channelmode` |
| **Total** | **10** | minimum 1 |

**Worked examples:**

| File | Bitrate | Low-pass | Sample rate | Mode | Channels | Score |
|------|---------|----------|-------------|------|----------|-------|
| VBR V0 / 44100 Hz / joint stereo / 19500 Hz | 4 | 2 | 2 | 1 | 1 | **10** |
| CBR 128 kbps / 44100 Hz / mono / 16000 Hz (typical voice note) | 2 | 1 | 2 | 0 | 0 | **5** |
| CBR 192 kbps / 44100 Hz / stereo / 18000 Hz | 3 | 1 | 2 | 0 | 1 | **7** |
| CBR 64 kbps / 22050 Hz / mono / 12000 Hz | 1 | 0 | 1 | 0 | 0 | **2** |
| VBR V2 / 44100 Hz / joint stereo / 19500 Hz | 3 | 2 | 2 | 1 | 1 | **9** |

---

### 5. Adaptive Queue Routing

**< 10 uploads total:** Analysis runs synchronously. Returns 201 with the full result immediately.

**≥ 10 uploads total:** Every new upload dispatches `AnalyzeAudioJob` to the queue. Returns 202 with a `job_id`. Client polls `GET /api/jobs/{jobId}`.

**Why the switch at 10:** Below 10, the log-IQR outlier strategy is also inactive (fixed thresholds apply). The two thresholds are intentionally aligned — below 10 is "bootstrap mode" with simple synchronous behaviour; at 10+ the system is in "operational mode" with adaptive outlier detection and background processing.

**Why duplicate detection is exempt from queueing:** Duplicates are detected before any file is stored. Returning a 202 for a known duplicate would force the client to poll for a result that is already fully available. Duplicates always return 200 synchronously.

**Why not file-size-based routing:** For voice notes in the 2s–3min range, file sizes are uniformly small. Count-based routing is simpler to test and directly tied to the outlier strategy alignment.

---

### 6. Queue Infrastructure — MongoDB Driver

`mongodb/laravel-mongodb` provides a MongoDB-backed queue driver. Jobs are stored in the `queue_jobs` collection.

**Why MongoDB over Redis for queueing:** Redis would require a fourth container. MongoDB is already present for application data. For the throughput expected from a voice note service, MongoDB queue performance is entirely sufficient.

**Why a dedicated `queue` service:** The worker runs as a separate container (same image, different command: `php artisan queue:work --tries=3`). This mirrors real-world deployments, starts automatically with `make up`, and isolates worker failures from the web process.

**Last-write-wins robustness:** MongoDB's `$set` operations on `audio_jobs` documents are atomic at the document level. If a worker updates a job's status from `processing` to `completed`, a concurrent stale update cannot partially overwrite the document — the last `$set` wins cleanly. This is the correct semantic for a job status field.

---

### 7. Database — MongoDB

**Why MongoDB over SQLite (original plan) or PostgreSQL:**

The system is described as a **high-volume write system**. MongoDB is selected for three concrete reasons:

1. **Write throughput:** MongoDB's WiredTiger storage engine is optimised for concurrent writes. SQLite uses file-level locking — a single write blocks all others. PostgreSQL is strong but heavier to configure in Docker.
2. **Last-write-wins safety:** When the same job document is updated by multiple paths (worker marks `completed`, a timeout marks `failed`), MongoDB's document-level atomicity ensures the last `$set` is applied cleanly with no partial-write corruption.
3. **Schemaless document model:** Audio metadata fields can vary across encoder versions and file types. MongoDB stores partial documents without requiring schema alterations.

**Trade-off:** MongoDB is heavier than SQLite, requires a separate container, and has no transaction support across multiple documents without explicit sessions. For this assignment's scope it is the right call; for a very low-traffic service, SQLite would be simpler.

---

### 8. HTTP Status Codes

| Scenario | Status | Reasoning |
|---------|--------|-----------|
| New upload, sync analysis | 201 Created | A new resource was created |
| New upload, queued | 202 Accepted | Request accepted; processing not yet complete |
| Duplicate detected | 200 OK | No new resource; returning existing analysis data |
| Job status poll (any state) | 200 OK | Informational — the poll itself always succeeds |
| Job not found | 404 Not Found | |
| Validation failure | 422 Unprocessable Entity | File is wrong type, too large, or missing |

**Why 200 (not 409 Conflict) for duplicates:** Returning 409 would force the client to make a second request to retrieve the analysis data. Returning 200 with the full analysis is more useful.

**Why 202 (not 200) for queued uploads:** RFC 7231 defines 202 as "the request has been accepted for processing, but the processing has not been completed." This is the precise contract.

---

## Trade-offs

| Decision | Trade-off |
|---------|-----------|
| MongoDB | High write throughput, last-write-wins safety. Heavier than SQLite; overkill for low traffic. |
| MongoDB queue driver | No Redis container. Slower than Redis at very high job throughput. |
| Full-file SHA-256 hash | Simple and accurate for small voice notes. Not suitable for large files. |
| Fixed thresholds < 10 records | Reliable at low data volumes. Not adaptive. |
| Log-IQR ≥ 10 records | Handles right-skewed distributions. Queries all durations on each upload. |
| Sync < 10 / async ≥ 10 | No queue infrastructure until the dataset justifies it. |

---

## What I'd Improve With More Time

- Cache log-IQR fence values (invalidate on new upload) to avoid querying all durations each time
- Merkle-tree chunking strategy for large file deduplication (audiobooks, long recordings)
- `GET /api/uploads` list endpoint with cursor-based MongoDB pagination
- Rate limiting on the upload endpoint
- MongoDB replica set in docker-compose for production-grade durability
- Streaming SHA-256 using `hash_update_stream`
- OpenAPI specification
- Encoder support beyond LAME: extract quality/version metadata from Xing- and Fraunhofer-tagged VBR files (currently `encoder`/`vbr_quality` are `null` for non-LAME files)
