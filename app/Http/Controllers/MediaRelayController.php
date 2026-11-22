<?php

namespace App\Http\Controllers;

use App\ContentSource;
use App\EpisodeSource;
use App\IngestAttempt;
use App\Jobs\IngestRelayUrlJob;
use App\Jobs\ProbeSourceLinkJob;
use App\MediaGallery;
use App\RelayEvent;
use App\RelayFile;
use App\RelayLink;
use App\Services\Relay\CachePromoter;
use App\Services\Relay\DemandTracker;
use App\Services\Relay\RelayManager;
use App\Services\Relay\SourceQuarantine;
use App\Services\Relay\SourceRequeuer;
use App\Services\Relay\Thumbnail;
use App\Services\Relay\UploadMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Admin panel for the relay: upload to Telegram Drive, watch demand, promote
 * into the 9drive cache and hand out site-domain links.
 */
class MediaRelayController extends Controller
{
    /** The "type:id" rows the failed-source table posts for bulk actions. */
    protected const SOURCE_PAIR_REGEX = '/^(episode|content):[0-9]+$/';

    /** @var RelayManager */
    protected $relay;

    /** @var CachePromoter */
    protected $promoter;

    /** @var DemandTracker */
    protected $demand;

    public function __construct(RelayManager $relay, CachePromoter $promoter, DemandTracker $demand)
    {
        $this->relay = $relay;
        $this->promoter = $promoter;
        $this->demand = $demand;
    }

    /**
     * Put one failed or retry-exhausted source back on the queue.
     *
     * Both download and upload failures are accepted: the source is queued
     * from the beginning again, and the failure counters stay as history.
     */
    public function requeueSource(Request $request)
    {
        [$sourceType, $sourceId] = $this->sourceKey($request);

        $result = app(SourceRequeuer::class)->requeue($sourceType, $sourceId);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Put a batch of failed rows back on the queue.
     *
     * A row that is no longer failed (already queued, downloading, blocked)
     * is reported instead of queued twice.
     */
    public function requeueSources(Request $request)
    {
        $done = 0;
        $skipped = [];

        foreach ($this->sourcePairs($request) as [$sourceType, $sourceId]) {
            $result = app(SourceRequeuer::class)->requeue($sourceType, $sourceId);

            if ($result['ok']) {
                $done++;
            } else {
                $skipped[] = $sourceType . ':' . $sourceId . ': ' . $result['message'];
            }
        }

        if ($done === 0) {
            return back()->with(
                'error',
                'هیچ منبعی دوباره صف نشد. ' . implode(' | ', array_slice($skipped, 0, 5))
            );
        }

        $message = $done . ' منبع دوباره به صف دانلود/آپلود اضافه شد.';

        if (!empty($skipped)) {
            $message .= ' ' . count($skipped) . ' مورد رد شد: '
                . implode(' | ', array_slice($skipped, 0, 5));
        }

        return back()->with('success', $message);
    }

    /**
     * Probe the link of one row, whatever its current status.
     *
     * Probing is what revives a quarantined (dead) row: the probe writes the
     * real answer back to link_status, so the requeue buttons accept the row
     * again once the origin answers.
     */
    public function probeSource(Request $request)
    {
        ProbeSourceLinkJob::dispatch(...$this->sourceKey($request));

        return back()->with('success', 'بررسی لینک در صف قرار گرفت؛ نتیجه بعد از پایان probe در ستون «لینک» دیده می‌شود.');
    }

    /**
     * Probe a batch of rows ("type:id" pairs) from the failed-source table.
     */
    public function probeSources(Request $request)
    {
        $pairs = $this->sourcePairs($request);

        foreach ($pairs as [$sourceType, $sourceId]) {
            ProbeSourceLinkJob::dispatch($sourceType, $sourceId);
        }

        return back()->with('success', count($pairs) . ' لینک برای بررسی در صف قرار گرفت.');
    }

    /**
     * The single "type:id" pair a row button posts.
     *
     * @return array{0:string,1:int}
     */
    protected function sourceKey(Request $request): array
    {
        $validated = $request->validate([
            'source_id' => 'required|integer|min:1',
            'source_type' => 'nullable|in:episode,content',
        ]);

        return [(string) ($validated['source_type'] ?? 'episode'), (int) $validated['source_id']];
    }

    /**
     * The unique, well-formed pairs a bulk button posts.
     *
     * @return array<int,array{0:string,1:int}>
     */
    protected function sourcePairs(Request $request): array
    {
        $validated = $request->validate([
            'sources' => 'required|array|max:200',
            'sources.*' => ['string', 'regex:' . self::SOURCE_PAIR_REGEX],
        ]);

        $pairs = [];

        foreach (array_unique($validated['sources']) as $pair) {
            [$sourceType, $sourceId] = explode(':', (string) $pair, 2);
            $pairs[] = [$sourceType, (int) $sourceId];
        }

        return $pairs;
    }

    public function mediaRelay()
    {
        [$failedSources, $failedSourcesCount] = $this->failedSources();

        // Only the newest few attempts of the listed rows are read (3 × rows).
        $attemptHistory = IngestAttempt::latestPerSource(
            $failedSources->map(fn ($source) => $source->source_type . ':' . $source->id)->all()
        );

        return view('mediaRelay', [
            'failedSources' => $failedSources,
            'failedSourcesCount' => $failedSourcesCount,
            'botSubmissions' => $this->botSubmissions(),
            'attemptHistory' => $attemptHistory,
            'downloadRetryMax' => max(1, min(10, (int) config('media_relay.ingest.retry_max_attempts', 3))),
            'uploadRetryMax' => max(1, min(10, (int) config('media_relay.upload.retry_max_attempts', 3))),
            'telegram' => $this->relay->telegram()->health(),
            'ninedrive' => $this->relay->ninedrive()->health(),
            'threshold' => $this->demand->threshold(),
            'stateStore' => $this->demand->storeName(),
            'statePersistent' => $this->demand->isPersistentStore(),
            'stateStoreOk' => $this->demand->checkStore()['ok'],
            'queue' => (string) (config('media_relay.queue.connection') ?: config('queue.default')),
            'cached' => RelayFile::where('cache_state', RelayFile::WARM)->count(),
            'cold' => RelayFile::whereIn('cache_state', [RelayFile::COLD, RelayFile::QUEUED, RelayFile::FAILED])->count(),
            'uploadStats' => UploadMetrics::summary(RelayFile::class),
            'ninedriveConfigured' => $this->relay->ninedrive()->isConfigured(),
            'telegramConfigured' => $this->relay->telegram()->isConfigured(),
        ]);
    }

    /**
     * The documents the bot accepted, newest first.
     *
     * The quality and series answers are decisions the person made in the
     * chat; until now they only existed inside the Telegram reply, so the panel
     * showed the file without what was chosen for it.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function botSubmissions()
    {
        if (!Schema::hasTable('bot_submissions')) {
            return collect();
        }

        try {
            return DB::table('bot_submissions')
                ->orderByDesc('id')
                ->limit(20)
                ->get();
        } catch (Throwable $e) {
            // A half-migrated panel must still render its relay page.
            return collect();
        }
    }

    /**
     * Download/upload failures and rows waiting for their next retry.
     *
     * Both source tables are included: movie content and episode sources use
     * the same ingest pipeline. A failed upload is also a retryable row here,
     * because UploadIngestJob keeps the .part file until its attempts run out.
     *
     * @return array{0:\Illuminate\Support\Collection,1:int}
     */
    protected function failedSources(): array
    {
        $rows = collect();
        $total = 0;

        $quarantine = app(SourceQuarantine::class);

        foreach (['episode' => EpisodeSource::class, 'content' => ContentSource::class] as $type => $class) {
            $table = $type === 'content' ? 'content_sources' : 'episode_sources';

            if (!Schema::hasTable($table)) {
                continue;
            }

            $query = $class::query()
                ->whereIn('ingest_status', ['failed', 'retrying'])
                ->where('source', 'like', 'http%');

            if ($type === 'episode' && Schema::hasColumn('episode_sources', 'is_blocked')) {
                $query->where('is_blocked', false);
            }

            $total += (clone $query)->count();

            $part = (clone $query)
                ->orderByDesc('updated_at')
                ->limit(25)
                ->get();

            foreach ($part as $row) {
                $row->source_type = $type;
                $row->quarantine_reason = $quarantine->reasonFor($row);
            }

            $rows = $rows->merge($part);
        }

        return [
            $rows->sortByDesc(fn ($row) => optional($row->updated_at)->getTimestamp() ?? 0)
                ->take(25)
                ->values(),
            $total,
        ];
    }

    public function relayFileList(Request $request)
    {
        $query = RelayFile::query();
        $totalData = $query->count();

        $limit = (int) $request->input('length', 10);
        $start = (int) $request->input('start', 0);
        $orderDir = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';
        $searchValue = $request->input('search.value');
        $state = strtolower((string) $request->input('state', ''));

        if (in_array($state, [RelayFile::WARM, RelayFile::COLD, RelayFile::QUEUED, RelayFile::PROMOTING, RelayFile::FAILED], true)) {
            $query->where('cache_state', $state);
        }

        if (!empty($searchValue)) {
            $query->where('name', 'LIKE', "%{$searchValue}%");
        }

        $totalFiltered = $query->count();

        $result = $query->with('links')->orderBy('id', $orderDir)
            ->offset($start)
            ->limit($limit)
            ->get();

        // One query instead of a count per row: which relay files already
        // have a Media Gallery entry that Content can pick.
        $libraryIds = MediaGallery::whereNotNull('relay_file_id')->pluck('relay_file_id')->all();

        $data = $result->map(function (RelayFile $item) use ($libraryIds) {
            $tier = $item->cache_state === RelayFile::WARM
                ? '<span class="badge bg-success">cache</span>'
                : '<span class="badge bg-secondary">' . e($item->cache_state) . '</span>';

            $thumb = $item->thumb
                ? "<img src='" . e(asset('storage/' . $item->thumb)) . "' width='72' height='40' class='rounded' style='object-fit:cover'>"
                : "<span class='text-muted'>—</span>";

            $name = e($item->name);

            if (in_array($item->id, $libraryIds, true)) {
                $name .= " <span class='badge bg-info' title='" . e(__('mediaLibraryHint')) . "'>" . __('inMediaLibrary') . '</span>';
            }

            if ($item->source_url) {
                $name .= "<br><span class='text-muted small d-inline-block text-truncate' style='max-width:280px' title='" . e($item->source_url) . "'>" . e(parse_url($item->source_url, PHP_URL_HOST) ?: $item->source_url) . '</span>';
            }

            if ($item->ingest_status && $item->ingest_status !== 'completed') {
                $attempt = (int) $item->ingest_attempts;
                $maxAttempts = max(1, min(10, (int) config('media_relay.ingest.retry_max_attempts', 3)));
                $errors = (int) $item->ingest_fail_count + (int) $item->upload_fail_count;
                $retryAt = $item->ingest_next_retry_at ?: $item->upload_next_retry_at;
                $detail = __('relayIngestAttempts') . ': ' . $attempt . '/' . $maxAttempts
                    . ' · ' . __('relayIngestErrors') . ': ' . $errors;

                if ($retryAt) {
                    $detail .= ' · ' . __('relayNextRetry') . ': ' . $retryAt->diffForHumans();
                }

                $title = e(trim($detail . ' | ' . (string) $item->ingest_error));

                if ($item->ingest_status === 'failed') {
                    $name .= "<br><span class='badge bg-danger' title='{$title}'>" . __('ingestFailed') . ' (' . $errors . ')</span>';
                } elseif ($item->ingest_status === 'retrying') {
                    $name .= "<br><span class='badge bg-warning text-dark' title='{$title}'>" . __('relayRetryPending') . ' ' . $attempt . '/' . $maxAttempts . '</span>';
                } else {
                    $name .= "<br><span class='badge bg-warning text-dark'>" . e($item->ingest_status) . ' ' . (int) $item->ingest_progress . '%</span>';
                }
            }

            $previewUrl = optional($item->links->first(function ($link) {
                return !$link->is_disabled;
            }))->streamUrl();

            $previewKind = str_starts_with((string) $item->mime, 'image/') ? 'image' : 'video';

            $preview = $previewUrl
                ? "<a href='#' class='me-2 btn btn-info px-3 text-white' data-media-preview='" . e($previewUrl) . "' data-media-kind='{$previewKind}'>" . __('preview') . '</a>'
                : '';

            $links = "<a rel='{$item->id}' class='me-2 btn btn-primary px-3 text-white relayLinks'>" . __('links') . "</a>";
            $promote = "<a href='#' rel='{$item->id}' class='me-2 btn btn-warning px-3 text-white promote'>" . __('promote') . "</a>";
            $evict = "<a href='#' rel='{$item->id}' class='me-2 btn btn-secondary px-3 text-white evict'>" . __('evict') . "</a>";
            $delete = "<a href='#' rel='{$item->id}' class='btn btn-danger px-3 text-white delete'>" . __('delete') . "</a>";

            return [
                "<input type='checkbox' class='relay-row-check' value='{$item->id}'>",
                $thumb,
                $name,
                $item->human_size,
                UploadMetrics::speedLabel($item),
                UploadMetrics::durationLabel($item),
                $tier,
                (int) $this->demand->count($item),
                (int) $item->hits,
                $item->last_access_at ? $item->last_access_at->diffForHumans() : '-',
                "<div class='text-end action'>{$preview}{$links}{$promote}{$evict}{$delete}</div>",
            ];
        });

        return response()->json([
            'draw' => intval($request->input('draw')),
            'recordsTotal' => intval($totalData),
            'recordsFiltered' => intval($totalFiltered),
            'data' => $data,
        ]);
    }

    /**
     * Upload a file straight into Telegram Drive (primary storage).
     */
    public function uploadRelayFile(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:' . ((int) config('media_relay.upload.max_size', 2048) * 1024),
        ]);

        if (!$this->relay->telegram()->isConfigured()) {
            return response()->json(['status' => false, 'message' => 'Telegram Drive is not configured.']);
        }

        $upload = $request->file('file');

        // Identical bytes already stored? A fresh link to the existing copy
        // beats a second multi-gigabyte upload.
        $duplicate = $this->relay->storage()->findByChecksum($upload->getRealPath());

        if ($duplicate) {
            $link = RelayLink::issueFor(
                $duplicate,
                $request->filled('name') ? (string) $request->input('name') : $upload->getClientOriginalName()
            );

            MediaGallery::registerRelayFile($duplicate, $link->streamUrl(), $link->label);

            RelayEvent::create([
                'relay_file_id' => $duplicate->id,
                'event' => 'uploaded',
                'served_by' => 'telegram',
                'bytes' => (int) $duplicate->size,
                'message' => 'Deduplicated upload: reused the stored copy.',
                'created_at' => now(),
            ]);

            return response()->json([
                'status' => true,
                'duplicate' => true,
                'message' => 'Identical file already stored; a new link was created without re-uploading.',
                'data' => $duplicate->only(['id', 'name', 'size', 'cache_state']),
            ]);
        }

        $name = $request->filled('name') ? (string) $request->input('name') : $upload->getClientOriginalName();

        try {
            $result = $this->relay->telegram()->uploadFromPath(
                $upload->getRealPath(),
                $name,
                $upload->getMimeType() ?: 'application/octet-stream',
                (string) config('media_relay.telegram.storage_chat', '')
            );
        } catch (Throwable $e) {
            Log::error('Relay upload failed', ['message' => $e->getMessage()]);

            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

        $fileId = $result['file_id'] ?? ($result['id'] ?? null);

        if (empty($fileId)) {
            return response()->json(['status' => false, 'message' => 'Telegram Drive did not return a file id.']);
        }

        $file = new RelayFile();
        $file->name = $name;
        $file->size = (int) ($result['size'] ?? $upload->getSize());
        $file->mime = $result['mime'] ?? $upload->getMimeType();
        $file->extension = $upload->getClientOriginalExtension();
        // Prefer Telegram Drive's sha256 (hash of the original file before
        // any split); fall back to hashing the local upload.
        $file->checksum = $result['sha256'] ?? (hash_file('sha256', $upload->getRealPath()) ?: null);
        $file->tg_file_id = (string) $fileId;
        $file->tg_backend = (string) config('media_relay.telegram.default_backend', 'telegram');
        $file->tg_storage_chat = (string) config('media_relay.telegram.storage_chat', '');
        $file->cache_state = RelayFile::COLD;
        $file->owner = (string) $request->session()->get('admin_name', 'panel');
        UploadMetrics::apply($file, UploadMetrics::fromResult($result, (int) $upload->getSize()));
        $file->save();

        // The temp upload still exists at this point; this is the only
        // moment a poster frame can be extracted cheaply.
        Thumbnail::make($upload->getRealPath(), (string) ($result['mime'] ?? $upload->getMimeType() ?? ''), $file);

        // Hand out a public link right away and expose the file in the media
        // library, so it is immediately usable inside Content.
        $link = RelayLink::issueFor($file, $name);

        MediaGallery::registerRelayFile($file, $link->streamUrl(), $name);

        RelayEvent::create([
            'relay_file_id' => $file->id,
            'event' => 'uploaded',
            'served_by' => 'telegram',
            'bytes' => (int) $file->size,
            'message' => 'Stored on Telegram Drive.',
            'created_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'File stored on Telegram Drive.',
            'data' => $file,
        ]);
    }

    /**
     * Add a file to the relay from a public URL. The row shows up immediately
     * (queued) and the job fills in the size, tier and link as it progresses.
     */
    public function relayFileFromUrl(Request $request)
    {
        $request->validate([
            'url' => 'required|url',
            'name' => 'nullable|string|max:255',
        ]);

        if (!$this->relay->telegram()->isConfigured()) {
            return response()->json(['status' => false, 'message' => 'Telegram Drive is not configured.']);
        }

        $url = trim((string) $request->input('url'));

        if (str_starts_with($url, url('/'))) {
            return response()->json(['status' => false, 'message' => 'This link is already on the relay.']);
        }

        $name = trim((string) $request->input('name'));

        if ($name === '') {
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $name = rawurldecode(basename($path)) ?: 'relay-' . Str::random(6);
        }

        $file = new RelayFile();
        $file->name = $name;
        $file->size = 0;
        $file->mime = $this->mimeFromName($name);
        $file->extension = pathinfo($name, PATHINFO_EXTENSION);
        $file->cache_state = RelayFile::COLD;
        $file->owner = (string) $request->session()->get('admin_name', 'panel');
        $file->source_url = $url;
        $file->ingest_status = 'queued';
        $file->ingest_progress = 0;
        $file->save();

        IngestRelayUrlJob::dispatch($file->id, $url);

        return response()->json([
            'status' => true,
            'message' => 'Queued: the file will appear here once it is on Telegram Drive.',
            'data' => $file->only(['id', 'name', 'ingest_status']),
        ]);
    }

    protected function mimeFromName(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'mp4' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'm3u8' => 'application/vnd.apple.mpegurl',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    /**
     * Remove the Media Gallery entries (and any source rows pointing at them)
     * for a relay file that is being deleted, so Content never keeps a dead id.
     */
    protected function purgeLibraryEntries(RelayFile $file): void
    {
        $mediaIds = MediaGallery::where('relay_file_id', $file->id)->pluck('id')->all();

        if (empty($mediaIds)) {
            return;
        }

        ContentSource::where('type', \App\Constants::FileType)->whereIn('source', $mediaIds)->delete();
        EpisodeSource::where('type', \App\Constants::FileType)->whereIn('source', $mediaIds)->delete();
        MediaGallery::whereIn('id', $mediaIds)->delete();
    }

    /**
     * Queue a source URL for ingestion into the combined storage.
     */
    public function ingestSourceUrl(Request $request)
    {
        if (!config('media_relay.ingest.enabled', true)) {
            return response()->json(['status' => false, 'message' => 'URL ingestion is disabled.']);
        }

        $request->validate([
            'source_type' => 'required|in:content,episode',
            'source_id' => 'required|integer',
            'url' => 'required|url',
        ]);

        $sourceType = (string) $request->input('source_type');
        $sourceId = (int) $request->input('source_id');

        $source = $sourceType === 'content'
            ? ContentSource::find($sourceId)
            : EpisodeSource::find($sourceId);

        if (!$source) {
            return response()->json(['status' => false, 'message' => 'Source not found.']);
        }

        $url = (string) $request->input('url');

        // Never queue a URL that already lives on our own relay.
        if (str_starts_with($url, url('/'))) {
            return response()->json(['status' => false, 'message' => 'This link is already on the relay.']);
        }

        $source->ingest_status = 'queued';
        $source->ingest_progress = 0;
        $source->ingest_error = null;
        $source->ingest_attempts = 0;
        $source->ingest_next_retry_at = null;
        $source->upload_status = null;
        $source->upload_attempts = 0;
        $source->upload_next_retry_at = null;
        $source->save();

        \App\Jobs\IngestUrlJob::dispatch($sourceType, $sourceId, $url, $this->ingestName($source));

        return response()->json([
            'status' => true,
            'message' => 'Queued for ingestion; the source will switch to the relay link when done.',
        ]);
    }

    protected function ingestName($source): string
    {
        $name = $source->title ?: 'ingest-' . $source->id;
        $extension = strtolower(pathinfo(parse_url($source->source, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        if ($extension && !str_ends_with(strtolower($name), '.' . $extension)) {
            $name .= '.' . $extension;
        }

        return $name;
    }

    /**
     * Apply an action to many relay files at once.
     */
    public function bulkRelayAction(Request $request)
    {
        $request->validate([
            'action' => 'required|in:promote,evict,delete',
            'relay_file_ids' => 'required|array|max:200',
            'relay_file_ids.*' => 'integer',
        ]);

        $ids = array_unique(array_map('intval', $request->input('relay_file_ids')));
        $action = (string) $request->input('action');
        $purgeTelegram = $request->boolean('purge_telegram');
        $purgeCache = $request->boolean('purge_cache');

        $done = 0;
        $failed = 0;

        foreach (RelayFile::whereIn('id', $ids)->get() as $file) {
            try {
                if ($action === 'promote') {
                    $result = $this->promoter->promote($file);

                    if (empty($result['ok']) && empty($result['skipped'])) {
                        throw new \RuntimeException($result['message'] ?? 'promotion failed');
                    }
                } elseif ($action === 'evict') {
                    $this->promoter->evict($file, $purgeCache);
                } else {
                    if ($purgeTelegram && !empty($file->tg_file_id)) {
                        try {
                            $this->relay->telegram()->deleteFile((string) $file->tg_file_id, true);
                        } catch (\Throwable $e) {
                            Log::warning('Telegram Drive purge failed', ['message' => $e->getMessage()]);
                        }
                    }

                    if ($purgeCache && !empty($file->nine_file_id)) {
                        $this->promoter->evict($file, true);
                    }

                    $this->purgeLibraryEntries($file);
                    RelayLink::where('relay_file_id', $file->id)->delete();
                    RelayEvent::where('relay_file_id', $file->id)->delete();
                    $file->delete();
                }

                $done++;
            } catch (\Throwable $e) {
                Log::warning("Relay bulk {$action} failed", [
                    'relay_file_id' => $file->id,
                    'message' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        return response()->json([
            'status' => true,
            'done' => $done,
            'failed' => $failed,
        ]);
    }

    /**
     * Receive one chunk of a resumable upload. Chunks append to a single
     * .part file under storage/app/tmp-uploads (persistently mounted), so a
     * stalled browser can continue from the last acknowledged chunk.
     */
    public function uploadRelayChunk(Request $request)
    {
        $request->validate([
            'upload_id' => 'required|regex:/^[a-f0-9]{32}$/',
            'index' => 'required|integer|min:0',
            'file' => 'required|file',
        ]);

        $dir = storage_path('app/tmp-uploads');

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $part = $dir . '/' . $request->input('upload_id') . '.part';
        $handle = @fopen($part, 'ab');

        if ($handle === false) {
            return response()->json(['status' => false, 'message' => 'Cannot open the upload target.']);
        }

        $stream = fopen($request->file('file')->getRealPath(), 'rb');

        while (!feof($stream)) {
            fwrite($handle, fread($stream, 1048576));
        }

        fclose($stream);
        fclose($handle);

        return response()->json([
            'status' => true,
            'index' => (int) $request->input('index'),
            'received' => filesize($part),
        ]);
    }

    /**
     * Acknowledge how many bytes the server already holds, so the browser
     * can resume an interrupted upload at the right offset.
     */
    public function uploadRelayStatus(Request $request)
    {
        $request->validate([
            'upload_id' => 'required|regex:/^[a-f0-9]{32}$/',
        ]);

        $part = storage_path('app/tmp-uploads/' . $request->input('upload_id') . '.part');

        return response()->json([
            'status' => true,
            'received' => is_file($part) ? filesize($part) : 0,
        ]);
    }

    /**
     * Finish a resumable upload: dedup-check, push the assembled file into
     * Telegram Drive, clean the temp part.
     */
    public function uploadRelayComplete(Request $request)
    {
        $request->validate([
            'upload_id' => 'required|regex:/^[a-f0-9]{32}$/',
            'name' => 'required|string|max:255',
        ]);

        $dir = storage_path('app/tmp-uploads');
        $part = $dir . '/' . $request->input('upload_id') . '.part';

        if (!is_file($part) || filesize($part) === 0) {
            return response()->json(['status' => false, 'message' => 'No assembled upload found.']);
        }

        if (!$this->relay->telegram()->isConfigured()) {
            return response()->json(['status' => false, 'message' => 'Telegram Drive is not configured.']);
        }

        $name = (string) $request->input('name');
        $mime = (string) ($request->input('mime') ?: 'application/octet-stream');

        try {
            $duplicate = $this->relay->storage()->findByChecksum($part);

            if ($duplicate) {
                $link = RelayLink::issueFor($duplicate, $name);

                MediaGallery::registerRelayFile($duplicate, $link->streamUrl(), $name);

                RelayEvent::create([
                    'relay_file_id' => $duplicate->id,
                    'event' => 'uploaded',
                    'served_by' => 'telegram',
                    'bytes' => (int) $duplicate->size,
                    'message' => 'Deduplicated upload: reused the stored copy.',
                    'created_at' => now(),
                ]);

                @unlink($part);

                return response()->json([
                    'status' => true,
                    'duplicate' => true,
                    'message' => 'Identical file already stored; a new link was created without re-uploading.',
                    'data' => $duplicate->only(['id', 'name', 'size', 'cache_state']),
                ]);
            }

            $result = $this->relay->telegram()->uploadFromPath(
                $part,
                $name,
                $mime,
                (string) config('media_relay.telegram.storage_chat', '')
            );
        } catch (Throwable $e) {
            Log::error('Relay chunked upload failed', ['message' => $e->getMessage()]);

            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

        $fileId = $result['file_id'] ?? ($result['id'] ?? null);

        if (empty($fileId)) {
            return response()->json(['status' => false, 'message' => 'Telegram Drive did not return a file id.']);
        }

        $file = new RelayFile();
        $file->name = $name;
        $file->size = (int) ($result['size'] ?? filesize($part));
        $file->mime = $result['mime'] ?? $mime;
        $file->extension = pathinfo($name, PATHINFO_EXTENSION);
        $file->checksum = $result['sha256'] ?? (hash_file('sha256', $part) ?: null);
        $file->tg_file_id = (string) $fileId;
        $file->tg_backend = (string) config('media_relay.telegram.default_backend', 'telegram');
        $file->tg_storage_chat = (string) config('media_relay.telegram.storage_chat', '');
        $file->cache_state = RelayFile::COLD;
        $file->owner = (string) $request->session()->get('admin_name', 'panel');
        UploadMetrics::apply($file, UploadMetrics::fromResult($result, (int) filesize($part)));
        $file->save();

        Thumbnail::make($part, (string) ($file->mime ?? $mime), $file);

        $link = RelayLink::issueFor($file, $name);

        MediaGallery::registerRelayFile($file, $link->streamUrl(), $name);

        RelayEvent::create([
            'relay_file_id' => $file->id,
            'event' => 'uploaded',
            'served_by' => 'telegram',
            'bytes' => (int) $file->size,
            'message' => 'Stored on Telegram Drive (chunked upload).',
            'created_at' => now(),
        ]);

        @unlink($part);

        return response()->json([
            'status' => true,
            'message' => 'File stored on Telegram Drive.',
            'data' => $file,
        ]);
    }

    /**
     * Promote a file into the cache now, or queue it when a worker exists.
     */
    public function promoteRelayFile(Request $request)
    {
        $file = RelayFile::find($request->input('relay_file_id'));

        if (!$file) {
            return response()->json(['status' => false, 'message' => 'File not found.']);
        }

        $queued = $this->relay->queuePromotion($file);

        if ($queued) {
            return response()->json(['status' => true, 'message' => 'Promotion queued.']);
        }

        $result = $this->promoter->promote($file);

        return response()->json([
            'status' => (bool) $result['ok'],
            'message' => $result['message'],
        ]);
    }

    public function evictRelayFile(Request $request)
    {
        $file = RelayFile::find($request->input('relay_file_id'));

        if (!$file) {
            return response()->json(['status' => false, 'message' => 'File not found.']);
        }

        $result = $this->promoter->evict($file, (bool) $request->input('delete_remote', false));

        return response()->json(['status' => (bool) $result['ok'], 'message' => $result['message']]);
    }

    public function deleteRelayFile(Request $request)
    {
        $file = RelayFile::find($request->input('relay_file_id'));

        if (!$file) {
            return response()->json(['status' => false, 'message' => 'File not found.']);
        }

        if ($request->boolean('purge_telegram') && !empty($file->tg_file_id)) {
            try {
                $this->relay->telegram()->deleteFile((string) $file->tg_file_id, true);
            } catch (Throwable $e) {
                Log::warning('Telegram Drive purge failed', ['message' => $e->getMessage()]);
            }
        }

        if ($request->boolean('purge_cache') && !empty($file->nine_file_id)) {
            $this->promoter->evict($file, true);
        }

        $this->purgeLibraryEntries($file);
        RelayLink::where('relay_file_id', $file->id)->delete();
        RelayEvent::where('relay_file_id', $file->id)->delete();
        $file->delete();

        return response()->json(['status' => true, 'message' => 'File removed.']);
    }

    public function toggleRelayBlock(Request $request)
    {
        $file = RelayFile::find($request->input('relay_file_id'));

        if (!$file) {
            return response()->json(['status' => false, 'message' => 'File not found.']);
        }

        $file->is_blocked = !$file->is_blocked;
        $file->save();

        return response()->json([
            'status' => true,
            'message' => $file->is_blocked ? 'File blocked.' : 'File unblocked.',
        ]);
    }

    /* ------------------------------------------------------------------ links */

    public function relayLinkList(Request $request)
    {
        $query = RelayLink::with('file')->where('relay_file_id', $request->input('relay_file_id'));
        $totalData = $query->count();

        $limit = (int) $request->input('length', 10);
        $start = (int) $request->input('start', 0);
        $orderDir = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';

        $result = $query->orderBy('id', $orderDir)->offset($start)->limit($limit)->get();

        $data = $result->map(function (RelayLink $link) {
            $stream = "<a href='{$link->streamUrl()}' target='_blank' class='me-2 btn btn-primary px-3 text-white'>" . __('preview') . "</a>";
            $copy = "<a href='#' class='me-2 btn btn-success px-3 text-white copyLink' data-url='{$link->downloadUrl()}'>" . __('copy') . "</a>";
            $toggle = "<a href='#' rel='{$link->id}' class='me-2 btn btn-warning px-3 text-white toggleLink'>" . ($link->is_disabled ? __('enable') : __('disable')) . "</a>";
            $delete = "<a href='#' rel='{$link->id}' class='btn btn-danger px-3 text-white deleteLink'>" . __('delete') . "</a>";

            return [
                e($link->token),
                e($link->mode),
                (int) $link->hits . ($link->max_downloads > 0 ? ' / ' . $link->max_downloads : ''),
                $link->is_disabled ? __('disabled') : __('active'),
                $link->expires_at ? $link->expires_at->toDateTimeString() : __('never'),
                "<div class='text-end action'>{$stream}{$copy}{$toggle}{$delete}</div>",
            ];
        });

        return response()->json([
            'draw' => intval($request->input('draw')),
            'recordsTotal' => intval($totalData),
            'recordsFiltered' => intval($totalData),
            'data' => $data,
        ]);
    }

    public function createRelayLink(Request $request)
    {
        $file = RelayFile::find($request->input('relay_file_id'));

        if (!$file) {
            return response()->json(['status' => false, 'message' => 'File not found.']);
        }

        $ttl = (int) $request->input('ttl', config('media_relay.relay.link_ttl', 0));

        // One creation point (file, token, label); the panel only adds the
        // options that make this link different from the default one.
        $link = RelayLink::issueFor($file, $request->input('label'));
        $link->mode = $request->input('mode') === RelayLink::MODE_DOWNLOAD
            ? RelayLink::MODE_DOWNLOAD
            : RelayLink::MODE_STREAM;
        $link->max_downloads = (int) $request->input('max_downloads', 0);
        $link->expires_at = $ttl > 0 ? now()->addSeconds($ttl) : null;

        if ($request->filled('password')) {
            $link->password_hash = Hash::make((string) $request->input('password'));
        }

        $link->save();

        return response()->json([
            'status' => true,
            'message' => 'Link created.',
            'data' => [
                'stream_url' => $link->streamUrl(),
                'download_url' => $link->downloadUrl(),
            ],
        ]);
    }

    public function toggleRelayLink(Request $request)
    {
        $link = RelayLink::find($request->input('relay_link_id'));

        if (!$link) {
            return response()->json(['status' => false, 'message' => 'Link not found.']);
        }

        $link->is_disabled = !$link->is_disabled;
        $link->save();

        return response()->json([
            'status' => true,
            'message' => $link->is_disabled ? 'Link disabled.' : 'Link enabled.',
        ]);
    }

    public function deleteRelayLink(Request $request)
    {
        $link = RelayLink::find($request->input('relay_link_id'));

        if (!$link) {
            return response()->json(['status' => false, 'message' => 'Link not found.']);
        }

        $link->delete();

        return response()->json(['status' => true, 'message' => 'Link deleted.']);
    }

    public function relayStatus()
    {
        return response()->json([
            'status' => true,
            'telegram' => $this->relay->telegram()->health(),
            'ninedrive' => $this->relay->ninedrive()->health(),
            'threshold' => $this->demand->threshold(),
            'state_store' => $this->demand->storeName(),
            'upload' => UploadMetrics::summary(RelayFile::class),
        ]);
    }
}
