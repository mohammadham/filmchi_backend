<?php

namespace Tests\Feature;

use App\RelayFile;
use App\Services\Relay\RelayStorage;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RelayDedupTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('relay_files')->delete();
        DB::table('relay_events')->delete();
        DB::table('relay_links')->delete();

        parent::tearDown();
    }

    public function test_find_by_checksum_returns_the_existing_copy()
    {
        $source = tempnam(sys_get_temp_dir(), 'dedup');
        file_put_contents($source, str_repeat('flixy-dedup-payload', 1000));

        $file = new RelayFile();
        $file->name = 'original.bin';
        $file->size = filesize($source);
        $file->mime = 'application/octet-stream';
        $file->cache_state = RelayFile::COLD;
        $file->checksum = hash_file('sha256', $source);
        $file->save();

        $storage = app(RelayStorage::class);
        $found = $storage->findByChecksum($source);

        $this->assertNotNull($found);
        $this->assertSame($file->id, $found->id);

        // A different payload must not match.
        file_put_contents($source, 'different-bytes');
        $this->assertNull($storage->findByChecksum($source));

        unlink($source);
    }

    public function test_dedup_skips_empty_or_missing_sources()
    {
        $storage = app(RelayStorage::class);

        $this->assertNull($storage->findByChecksum('/definitely/not/here'));
        $this->assertNull($storage->findByChecksum(tempnam(sys_get_temp_dir(), 'empty')));
    }
}
