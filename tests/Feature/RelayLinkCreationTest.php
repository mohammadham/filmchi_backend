<?php

namespace Tests\Feature;

use App\RelayFile;
use App\RelayLink;
use App\Services\Relay\RelayStorage;
use App\Services\Relay\TelegramDriveClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every path that stores bytes from the panel ends in RelayLink::issueFor().
 * These pin the link each one hands out: a fresh random token, the mode the
 * caller asked for, and a label the user can recognise.
 *
 * Only the two services that talk to Telegram Drive are replaced; the rows,
 * the routes and the link itself are the real ones.
 */
class RelayLinkCreationTest extends TestCase
{
    /** Every row this suite creates carries this prefix. */
    protected const PREFIX = 'linktest-';

    protected array $parts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeTelegram();
        $this->fakeStorage();
    }

    protected function tearDown(): void
    {
        foreach ($this->parts as $part) {
            @unlink($part);
        }

        $ids = RelayFile::where('name', 'like', self::PREFIX . '%')->pluck('id');

        DB::table('relay_links')->whereIn('relay_file_id', $ids)->delete();
        DB::table('relay_events')->whereIn('relay_file_id', $ids)->delete();

        if (Schema::hasTable('media_gallery')) {
            DB::table('media_gallery')->whereIn('relay_file_id', $ids)->delete();
        }

        RelayFile::whereIn('id', $ids)->delete();

        parent::tearDown();
    }

    /* ------------------------------------------------------------- fakes */

    /** The client that would upload to Telegram Drive. */
    protected function fakeTelegram(): void
    {
        $client = new class extends TelegramDriveClient {
            /** @var array<int, string> */
            public array $uploaded = [];

            public function __construct()
            {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function uploadFromPath(
                string $path,
                string $name,
                string $mime = null,
                string $storageChat = null,
                string $folder = null
            ): array {
                $this->uploaded[] = $name;

                return [
                    'file_id' => 'tg-' . $name,
                    'size' => is_file($path) ? filesize($path) : 0,
                    'mime' => $mime ?: 'application/octet-stream',
                    'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
                ];
            }
        };

        $this->app->instance(TelegramDriveClient::class, $client);
    }

    /** The storage lookup that answers "these bytes are already stored". */
    protected function fakeStorage(?RelayFile $duplicate = null): void
    {
        $storage = new class extends RelayStorage {
            public ?RelayFile $duplicate = null;

            public function __construct()
            {
            }

            public function findByChecksum(string $path, ?string $checksum = null): ?RelayFile
            {
                return $this->duplicate;
            }
        };

        $storage->duplicate = $duplicate;

        $this->app->instance(RelayStorage::class, $storage);
    }

    /* ------------------------------------------------------------ helpers */

    protected function panel()
    {
        return $this->withSession(['user_name' => 'admin', 'admin_name' => 'admin']);
    }

    protected function storedFile(string $name, string $size = '4096'): RelayFile
    {
        $file = new RelayFile();
        $file->name = self::PREFIX . $name;
        $file->size = (int) $size;
        $file->mime = 'application/octet-stream';
        $file->cache_state = RelayFile::COLD;
        $file->save();

        return $file;
    }

    protected function fileCount(): int
    {
        return RelayFile::where('name', 'like', self::PREFIX . '%')->count();
    }

    /** The one link the request just created. */
    protected function newestLink(): RelayLink
    {
        return RelayLink::orderByDesc('id')->firstOrFail();
    }

    protected function assertLink(
        RelayLink $link,
        int $fileId,
        string $label,
        string $mode = RelayLink::MODE_STREAM
    ): void {
        $this->assertSame(40, strlen((string) $link->token), 'the token is 40 random characters');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', (string) $link->token);
        $this->assertSame($mode, $link->mode);
        $this->assertSame($label, $link->label);
        $this->assertSame($fileId, (int) $link->relay_file_id);
    }

    /* --------------------------------------------------------------- upload */

    public function test_a_single_upload_hands_out_a_stream_link_labelled_with_the_typed_name(): void
    {
        $this->panel()
            ->post('uploadRelayFile', [
                'file' => UploadedFile::fake()->create(self::PREFIX . 'upload.bin', 8),
                'name' => self::PREFIX . 'movie.mkv',
            ])
            ->assertOk()
            ->assertJsonPath('status', true);

        $file = RelayFile::where('name', self::PREFIX . 'movie.mkv')->firstOrFail();

        $this->assertSame(1, $this->fileCount());
        $this->assertLink($this->newestLink(), (int) $file->id, self::PREFIX . 'movie.mkv');
    }

    public function test_an_upload_without_a_typed_name_labels_the_link_with_the_original_file_name(): void
    {
        $this->panel()
            ->post('uploadRelayFile', [
                'file' => UploadedFile::fake()->create(self::PREFIX . 'original.bin', 8),
            ])
            ->assertOk()
            ->assertJsonPath('status', true);

        $file = RelayFile::where('name', self::PREFIX . 'original.bin')->firstOrFail();

        $this->assertLink($this->newestLink(), (int) $file->id, self::PREFIX . 'original.bin');
    }

    public function test_a_duplicate_upload_reuses_the_stored_copy_and_gets_its_own_link(): void
    {
        $stored = $this->storedFile('already-stored.mkv');
        $this->fakeStorage($stored);

        $this->panel()
            ->post('uploadRelayFile', [
                'file' => UploadedFile::fake()->create(self::PREFIX . 'again.bin', 8),
                'name' => self::PREFIX . 'second-copy.mkv',
            ])
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, $this->fileCount(), 'the duplicate never creates a second relay file');
        $this->assertLink($this->newestLink(), (int) $stored->id, self::PREFIX . 'second-copy.mkv');
    }

    /* ------------------------------------------------------- chunked upload */

    public function test_a_chunked_upload_mints_the_link_only_when_the_last_chunk_is_completed(): void
    {
        $uploadId = str_repeat('a', 32);
        $part = storage_path('app/tmp-uploads/' . $uploadId . '.part');
        $this->parts[] = $part;
        $linksBefore = RelayLink::count();

        $this->panel()
            ->post('uploadRelayChunk', [
                'upload_id' => $uploadId,
                'index' => 0,
                'file' => UploadedFile::fake()->createWithContent('chunk.bin', str_repeat('flixy-chunk-', 64)),
            ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('index', 0);

        $this->assertFileExists($part);
        $this->assertSame($linksBefore, RelayLink::count(), 'a chunk on its own is not a link yet');
        $this->assertSame(0, $this->fileCount());

        $this->panel()
            ->post('uploadRelayComplete', [
                'upload_id' => $uploadId,
                'name' => self::PREFIX . 'chunked.mkv',
                'mime' => 'application/octet-stream',
            ])
            ->assertOk()
            ->assertJsonPath('status', true);

        $file = RelayFile::where('name', self::PREFIX . 'chunked.mkv')->firstOrFail();

        $this->assertLink($this->newestLink(), (int) $file->id, self::PREFIX . 'chunked.mkv');
        $this->assertFileDoesNotExist($part, 'the assembled part is removed once it is stored');
    }

    public function test_a_duplicate_chunked_upload_reuses_the_stored_copy_and_still_mints_a_link(): void
    {
        $stored = $this->storedFile('chunk-duplicate.mkv');
        $this->fakeStorage($stored);

        $uploadId = str_repeat('b', 32);
        $part = storage_path('app/tmp-uploads/' . $uploadId . '.part');
        $this->parts[] = $part;

        $this->panel()->post('uploadRelayChunk', [
            'upload_id' => $uploadId,
            'index' => 0,
            'file' => UploadedFile::fake()->createWithContent('chunk.bin', str_repeat('same-bytes-', 32)),
        ])->assertOk();

        $this->panel()
            ->post('uploadRelayComplete', [
                'upload_id' => $uploadId,
                'name' => self::PREFIX . 'chunk-second-copy.mkv',
                'mime' => 'application/octet-stream',
            ])
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, $this->fileCount(), 'the duplicate never creates a second relay file');
        $this->assertLink($this->newestLink(), (int) $stored->id, self::PREFIX . 'chunk-second-copy.mkv');
        $this->assertFileDoesNotExist($part);
    }

    /* -------------------------------------------------------- panel links */

    public function test_create_relay_link_defaults_to_stream_and_the_file_name(): void
    {
        $file = $this->storedFile('defaults.mkv');

        $this->panel()
            ->post('createRelayLink', ['relay_file_id' => $file->id])
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertLink($this->newestLink(), (int) $file->id, $file->name);
    }

    public function test_create_relay_link_keeps_a_download_mode_and_the_typed_label(): void
    {
        $file = $this->storedFile('downloads.mkv');

        $this->panel()
            ->post('createRelayLink', [
                'relay_file_id' => $file->id,
                'mode' => 'download',
                'label' => 'برچسب دلخواه',
            ])
            ->assertOk();

        $link = $this->newestLink();

        $this->assertLink($link, (int) $file->id, 'برچسب دلخواه', RelayLink::MODE_DOWNLOAD);
        $this->assertStringContainsString($link->token, $link->downloadUrl());
    }

    public function test_an_unknown_mode_falls_back_to_stream(): void
    {
        $file = $this->storedFile('bad-mode.mkv');

        $this->panel()
            ->post('createRelayLink', [
                'relay_file_id' => $file->id,
                'mode' => 'playlist',
                'label' => self::PREFIX . 'falls-back',
            ])
            ->assertOk();

        $this->assertSame(RelayLink::MODE_STREAM, $this->newestLink()->mode);
    }

    public function test_two_links_on_the_same_file_never_share_a_token(): void
    {
        $file = $this->storedFile('shared.mkv');

        $this->panel()->post('createRelayLink', ['relay_file_id' => $file->id])->assertOk();
        $first = $this->newestLink();

        $this->panel()->post('createRelayLink', ['relay_file_id' => $file->id])->assertOk();
        $second = $this->newestLink();

        $this->assertNotSame($first->token, $second->token);
        $this->assertSame(2, RelayLink::where('relay_file_id', $file->id)->count());
    }
}
