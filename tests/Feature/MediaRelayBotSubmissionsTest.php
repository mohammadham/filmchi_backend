<?php

namespace Tests\Feature;

use App\Http\Controllers\MediaRelayController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The quality and series answers were only ever echoed back in the Telegram
 * chat; the panel's bot list has to show what was stored, newest first.
 */
class MediaRelayBotSubmissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The relay page probes both upstreams for its health badges.
        Http::fake(['*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();

        parent::tearDown();
    }

    protected function clean(): void
    {
        DB::table('bot_submissions')->where('file_unique_id', 'like', 'panel-test-%')->delete();
    }

    protected function submission(array $overrides = []): void
    {
        DB::table('bot_submissions')->insert($overrides + [
            'telegram_user_id' => 111,
            'chat_id' => 111,
            'message_id' => 10,
            'file_unique_id' => 'panel-test-1',
            'name' => 'movie.mkv',
            'size' => 2048,
            'mime' => 'video/x-matroska',
            'quality' => '720',
            'is_series' => 0,
            'status' => 'done',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_panel_lists_the_saved_quality_and_series_answers(): void
    {
        $this->submission([
            'file_unique_id' => 'panel-test-1',
            'name' => 'first.mkv',
            'message_id' => 10,
            'quality' => '720',
            'is_series' => 0,
        ]);
        $this->submission([
            'file_unique_id' => 'panel-test-2',
            'name' => 'second.mkv',
            'message_id' => 11,
            'quality' => '1080',
            'is_series' => 1,
        ]);

        $rows = app(MediaRelayController::class)
            ->mediaRelay()
            ->getData()['botSubmissions']
            ->whereIn('file_unique_id', ['panel-test-1', 'panel-test-2'])
            ->values();

        $this->assertCount(2, $rows);
        $this->assertSame('1080', $rows[0]->quality, 'the newest submission comes first');
        $this->assertSame(1, (int) $rows[0]->is_series);

        $html = view('partials.botSubmissions', ['botSubmissions' => $rows])->render();

        $this->assertStringContainsString('second.mkv', $html);
        $this->assertStringContainsString('1080', $html);
        $this->assertStringContainsString('720', $html);
        $this->assertStringContainsString(__('botSubmissionSeriesYes'), $html);
        $this->assertStringContainsString(__('botSubmissionSeriesNo'), $html);

        // the answers are correctable per row, prefilled with what is stored
        $this->assertStringContainsString('botSubmissionEdit', $html);
        $this->assertStringContainsString('data-quality="1080"', $html);
        $this->assertStringContainsString('data-series="1"', $html);
    }

    /** One save, called the way the panel calls it. */
    protected function saveAnswers(int $id, ?string $quality, bool $series): array
    {
        $response = app(MediaRelayController::class)->updateBotSubmission(
            Request::create('updateBotSubmission', 'POST', [
                'submission_id' => $id,
                'quality' => $quality,
                'is_series' => $series ? 1 : 0,
            ])
        );

        return json_decode((string) $response->getContent(), true);
    }

    public function test_a_corrected_quality_and_series_flag_are_saved(): void
    {
        $this->submission(['file_unique_id' => 'panel-test-4', 'quality' => '720', 'is_series' => 0]);

        $id = (int) DB::table('bot_submissions')->where('file_unique_id', 'panel-test-4')->value('id');

        $this->assertSame(['status' => true, 'message' => 'Bot submission updated.'], $this->saveAnswers($id, '1080', true));

        $row = DB::table('bot_submissions')->where('id', $id)->first();

        $this->assertSame('1080', $row->quality);
        $this->assertSame(1, (int) $row->is_series);
    }

    public function test_an_empty_quality_clears_the_answer_and_keeps_the_series_flag(): void
    {
        $this->submission(['file_unique_id' => 'panel-test-5', 'quality' => '480', 'is_series' => 1]);

        $id = (int) DB::table('bot_submissions')->where('file_unique_id', 'panel-test-5')->value('id');

        $this->assertTrue($this->saveAnswers($id, '', true)['status']);

        $row = DB::table('bot_submissions')->where('id', $id)->first();

        $this->assertNull($row->quality, '"no answer" is stored as null, like a file the chat never answered');
        $this->assertSame(1, (int) $row->is_series);
    }

    public function test_an_unknown_submission_changes_nothing(): void
    {
        $this->submission(['file_unique_id' => 'panel-test-6', 'quality' => '720', 'is_series' => 0]);

        $id = (int) DB::table('bot_submissions')->where('file_unique_id', 'panel-test-6')->value('id');

        $this->assertSame(
            ['status' => false, 'message' => 'Submission not found.'],
            $this->saveAnswers($id + 100000, '1080', true)
        );

        $this->assertSame('720', DB::table('bot_submissions')->where('id', $id)->value('quality'));
    }

    public function test_a_quality_the_bot_never_offered_is_refused(): void
    {
        $this->submission(['file_unique_id' => 'panel-test-7', 'quality' => '720']);

        $id = (int) DB::table('bot_submissions')->where('file_unique_id', 'panel-test-7')->value('id');

        $this->expectException(ValidationException::class);

        $this->saveAnswers($id, '4k', false);
    }

    public function test_a_submission_without_an_answer_shows_a_dash(): void
    {
        $this->submission(['file_unique_id' => 'panel-test-3', 'quality' => null]);

        $rows = DB::table('bot_submissions')
            ->where('file_unique_id', 'panel-test-3')
            ->get();

        $html = view('partials.botSubmissions', ['botSubmissions' => $rows])->render();

        $this->assertStringContainsString('—', $html, 'a missing quality is a dash, not an empty cell');
    }

    public function test_the_card_says_so_when_the_bot_has_no_submissions(): void
    {
        $html = view('partials.botSubmissions', ['botSubmissions' => collect()])->render();

        $this->assertStringContainsString(__('botSubmissionsEmpty'), $html);
    }
}
