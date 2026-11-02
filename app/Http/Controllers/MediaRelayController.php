<?php

namespace App\Http\Controllers;

use App\ContentSource;
use App\EpisodeSource;
use App\RelayEvent;
use App\RelayFile;
use App\RelayLink;
use App\Services\Relay\CachePromoter;
use App\Services\Relay\DemandTracker;
use App\Services\Relay\RelayManager;
use App\Services\Relay\Thumbnail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Admin panel for the relay: upload to Telegram Drive, watch demand, promote
 * into the 9drive cache and hand out site-domain links.
 */
class MediaRelayController extends Controller
{
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

    public function mediaRelay()
    {
        return view('mediaRelay', [
            'telegram' => $this->relay->telegram()->health(),
            'ninedrive' => $this->relay->ninedrive()->health(),
            'threshold' => $this->demand->threshold(),
            'stateStore' => $this->demand->storeName(),
            'statePersistent' => $this->demand->isPersistentStore(),
            'stateStoreOk' => $this->demand->checkStore()['ok'],
            'queue' => (string) (config('media_relay.queue.connection') ?: config('queue.default')),
            'cached' => RelayFile::where('cache_state', RelayFile::WARM)->count(),
            'cold' => RelayFile::whereIn('cache_state', [RelayFile::COLD, RelayFile::QUEUED, RelayFile::FAILED])->count(),
            'ninedriveConfigured' => $this->relay->ninedrive()->isConfigured(),
            'telegramConfigured' => $this->relay->telegram()->isConfigured(),
        ]);
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

        $result = $query->orderBy('id', $orderDir)
            ->offset($start)
            ->limit($limit)
            ->get();

        $data = $result->map(function (RelayFile $item) {
            $tier = $item->cache_state === RelayFile::WARM
                ? '<span class="badge bg-success">cache</span>'
                : '<span class="badge bg-secondary">' . e($item->cache_state) . '</span>';

            $thumb = $item->thumb
                ? "<img src='" . e(asset('storage/' . $item->thumb)) . "' width='72' height='40' class='rounded' style='object-fit:cover'>"
                : "<span class='text-muted'>—</span>";

            $links = "<a rel='{$item->id}' class='me-2 btn btn-primary px-3 text-white relayLinks'>" . __('links') . "</a>";
            $promote = "<a href='#' rel='{$item->id}' class='me-2 btn btn-warning px-3 text-white promote'>" . __('promote') . "</a>";
            $evict = "<a href='#' rel='{$item->id}' class='me-2 btn btn-secondary px-3 text-white evict'>" . __('evict') . "</a>";
            $delete = "<a href='#' rel='{$item->id}' class='btn btn-danger px-3 text-white delete'>" . __('delete') . "</a>";

            return [
                "<input type='checkbox' class='relay-row-check' value='{$item->id}'>",
                $thumb,
                e($item->name),
                $item->human_size,
                $tier,
                (int) $this->demand->count($item),
                (int) $item->hits,
                $item->last_access_at ? $item->last_access_at->diffForHumans() : '-',
                "<div class='text-end action'>{$links}{$promote}{$evict}{$delete}</div>",
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
            $link = new RelayLink();
            $link->relay_file_id = $duplicate->id;
            $link->token = Str::random(40);
            $link->mode = RelayLink::MODE_STREAM;
            $link->label = $request->filled('name') ? (string) $request->input('name') : $upload->getClientOriginalName();
            $link->save();

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
        $file->save();

        // The temp upload still exists at this point; this is the only
        // moment a poster frame can be extracted cheaply.
        Thumbnail::make($upload->getRealPath(), (string) ($result['mime'] ?? $upload->getMimeType() ?? ''), $file);

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
                $link = new RelayLink();
                $link->relay_file_id = $duplicate->id;
                $link->token = Str::random(40);
                $link->mode = RelayLink::MODE_STREAM;
                $link->label = $name;
                $link->save();

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
        $file->save();

        Thumbnail::make($part, (string) ($file->mime ?? $mime), $file);

        $link = new RelayLink();
        $link->relay_file_id = $file->id;
        $link->token = Str::random(40);
        $link->mode = RelayLink::MODE_STREAM;
        $link->label = $name;
        $link->save();

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

        $link = new RelayLink();
        $link->relay_file_id = $file->id;
        $link->token = Str::random(40);
        $link->mode = $request->input('mode') === RelayLink::MODE_DOWNLOAD
            ? RelayLink::MODE_DOWNLOAD
            : RelayLink::MODE_STREAM;
        $link->label = $request->input('label');
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
        ]);
    }
}
