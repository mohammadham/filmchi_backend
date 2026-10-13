<?php

namespace App\Http\Controllers;

use App\RelayEvent;
use App\RelayFile;
use App\RelayLink;
use App\Services\Relay\CachePromoter;
use App\Services\Relay\DemandTracker;
use App\Services\Relay\RelayManager;
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

            $links = "<a rel='{$item->id}' class='me-2 btn btn-primary px-3 text-white relayLinks'>" . __('links') . "</a>";
            $promote = "<a href='#' rel='{$item->id}' class='me-2 btn btn-warning px-3 text-white promote'>" . __('promote') . "</a>";
            $evict = "<a href='#' rel='{$item->id}' class='me-2 btn btn-secondary px-3 text-white evict'>" . __('evict') . "</a>";
            $delete = "<a href='#' rel='{$item->id}' class='btn btn-danger px-3 text-white delete'>" . __('delete') . "</a>";

            return [
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
            'file' => 'required|file',
        ]);

        if (!$this->relay->telegram()->isConfigured()) {
            return response()->json(['status' => false, 'message' => 'Telegram Drive is not configured.']);
        }

        $upload = $request->file('file');
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
        $file->tg_file_id = (string) $fileId;
        $file->tg_backend = (string) config('media_relay.telegram.default_backend', 'telegram');
        $file->tg_storage_chat = (string) config('media_relay.telegram.storage_chat', '');
        $file->cache_state = RelayFile::COLD;
        $file->owner = (string) $request->session()->get('admin_name', 'panel');
        $file->save();

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
