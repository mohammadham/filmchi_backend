<?php

namespace App\Http\Controllers;

use App\RelayLink;
use App\Services\Relay\RelayManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Public endpoints behind every relay link.
 *
 * Visitors only ever see this site's own domain; the bytes come from whichever
 * tier currently holds the file (9drive cache first, Telegram Drive otherwise).
 */
class RelayController extends Controller
{
    /** @var RelayManager */
    protected $relay;

    public function __construct(RelayManager $relay)
    {
        $this->relay = $relay;
    }

    public function stream(Request $request, $token)
    {
        return $this->serve($request, (string) $token, false);
    }

    public function download(Request $request, $token)
    {
        return $this->serve($request, (string) $token, true);
    }

    protected function serve(Request $request, string $token, bool $download)
    {
        if (!config('media_relay.enabled')) {
            return $this->plain('دسترسی به این بخش غیرفعال است.', 404);
        }

        $link = RelayLink::where('token', $token)->first();

        if (!$link) {
            return $this->plain('لینک یافت نشد.', 404);
        }

        if (!$link->isUsable()) {
            return $this->plain('این لینک منقضی شده یا غیرفعال است.', 410);
        }

        $file = $link->file;

        if (!$file) {
            return $this->plain('فایل یافت نشد.', 404);
        }

        if ($file->is_blocked) {
            return $this->plain('این فایل مسدود شده است.', 403);
        }

        if (!empty($link->password_hash)) {
            $supplied = (string) ($request->query('pw') ?: $request->header('X-Link-Password'));

            if ($supplied === '' || !Hash::check($supplied, $link->password_hash)) {
                return $this->plain('این لینک رمزدار است. رمز را در پارامتر pw بفرستید.', 401);
            }
        }

        $link->hits = (int) $link->hits + 1;
        $link->last_used_at = now();
        $link->save();

        // Demand is what decides whether the file gets promoted into the cache.
        $this->relay->trackDemand($file, $request, $download);

        try {
            return $this->relay->serve($file, $request, $download);
        } catch (\Throwable $e) {
            // An unreachable storage tier must not surface as a 500 to a viewer.
            \Illuminate\Support\Facades\Log::error('Relay serve failed', [
                'relay_file_id' => $file->id,
                'message' => $e->getMessage(),
            ]);

            return $this->plain('سرویس ذخیره‌سازی موقتاً در دسترس نیست. لطفاً بعداً تلاش کنید.', 503)
                ->header('Retry-After', '60');
        }
    }

    protected function plain(string $message, int $status)
    {
        return response($message . "\n", $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
