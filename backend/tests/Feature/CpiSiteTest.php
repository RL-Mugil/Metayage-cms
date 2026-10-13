<?php

namespace Tests\Feature;

use App\Models\CpiArticle;
use App\Models\CpiBooking;
use App\Models\SiteEnquiry;
use App\Models\User;
use App\Services\CpiBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CpiSiteTest extends TestCase
{
    use RefreshDatabase;

    private string $siteDir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.contact.recipient' => 'inbox@example.com', 'services.turnstile.secret_key' => null, 'mail.default' => 'array']);
        $this->siteDir = sys_get_temp_dir().'/cpi-site-'.uniqid();
        File::ensureDirectoryExists($this->siteDir);
        config(['services.cpi.site_path' => $this->siteDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->siteDir);
        parent::tearDown();
    }

    private function adminHeaders(): array
    {
        User::create(['name' => 'Admin', 'email' => 'root@test.local', 'password' => bcrypt('secret-pass-1'), 'role' => 'super_admin', 'status' => 'Active']);
        $token = $this->postJson('/api/cpi-admin/login', ['email' => 'root@test.local', 'password' => 'secret-pass-1'])->json('token');

        return ['Authorization' => 'Bearer '.$token];
    }

    /** First open slot, as [date, utc-iso]. */
    private function firstSlot(): array
    {
        $svc = new CpiBookingService();
        foreach ($svc->openDays() as $day) {
            $slots = $svc->slotsForDate($day);
            if ($slots) {
                return [$day, $slots[0]];
            }
        }
        $this->fail('No open slot found with the default availability.');
    }

    private function bookingPayload(string $start, array $extra = []): array
    {
        return array_merge(['start' => $start, 'name' => 'Asha Rao', 'email' => 'asha@example.com', 'elapsed_ms' => 9000], $extra);
    }

    // ---- Quiz ----

    public function test_quiz_submission_is_stored_with_answers(): void
    {
        $this->postJson('/api/public/contact', [
            'name' => 'Asha Rao', 'email' => 'asha@example.com', 'message' => 'Patentability self-check result: example',
            'source' => 'canpatentit-quiz', 'answers' => ['what' => 'software', 'stage' => 'proto'], 'elapsed_ms' => 9000,
        ])->assertCreated();

        $row = SiteEnquiry::first();
        $this->assertSame('quiz', $row->type);
        $this->assertSame('software', $row->payload['what']);
    }

    // ---- Booking ----

    public function test_open_days_and_slots_are_offered(): void
    {
        $this->getJson('/api/public/cpi/days')->assertOk()->assertJsonStructure(['days', 'timezone']);
        [$day] = $this->firstSlot();
        $this->getJson('/api/public/cpi/slots?date='.$day)->assertOk()->assertJsonStructure(['slots']);
    }

    public function test_booking_succeeds_then_same_slot_is_refused(): void
    {
        [, $slot] = $this->firstSlot();

        $this->postJson('/api/public/cpi/book', $this->bookingPayload($slot))->assertCreated();
        $this->postJson('/api/public/cpi/book', $this->bookingPayload($slot, ['email' => 'other@example.com']))->assertStatus(409);

        $this->assertSame(1, CpiBooking::where('status', 'confirmed')->count());
        $this->assertDatabaseHas('site_enquiries', ['type' => 'booking', 'email' => 'asha@example.com']);
    }

    public function test_slot_outside_availability_is_refused(): void
    {
        $sunday = CarbonImmutable::now('UTC')->next('Sunday')->setTime(10, 0)->format('Y-m-d\TH:i:s\Z');

        $this->postJson('/api/public/cpi/book', $this->bookingPayload($sunday))->assertStatus(409);
    }

    public function test_cancelling_frees_the_slot(): void
    {
        [$day, $slot] = $this->firstSlot();
        $this->postJson('/api/public/cpi/book', $this->bookingPayload($slot))->assertCreated();
        $this->assertNotContains($slot, (new CpiBookingService())->slotsForDate($day));

        $booking = CpiBooking::first();
        (new CpiBookingService())->cancel($booking);

        $this->assertContains($slot, (new CpiBookingService())->slotsForDate($day));
    }

    public function test_cancel_link_needs_a_post_and_the_right_token(): void
    {
        [, $slot] = $this->firstSlot();
        $this->postJson('/api/public/cpi/book', $this->bookingPayload($slot))->assertCreated();
        $b = CpiBooking::first();

        $this->post("/api/public/cpi/cancel/{$b->id}/wrong-token")->assertOk();
        $this->assertSame('confirmed', $b->fresh()->status);

        $this->get("/api/public/cpi/cancel/{$b->id}/wrong-token")->assertOk();
        $this->assertSame('confirmed', $b->fresh()->status);
    }

    public function test_honeypot_booking_is_silently_ignored(): void
    {
        [, $slot] = $this->firstSlot();

        $this->postJson('/api/public/cpi/book', $this->bookingPayload($slot, ['website' => 'http://spam.test']))->assertOk();
        $this->assertSame(0, CpiBooking::count());
    }

    public function test_blackout_day_has_no_slots(): void
    {
        $h = $this->adminHeaders();
        [$day] = $this->firstSlot();
        $current = $this->getJson('/api/cpi-admin/bookings', $h)->json();

        $this->putJson('/api/cpi-admin/availability', [
            'rules' => $current['rules'], 'blackouts' => [['day' => $day, 'note' => 'Holiday']], 'settings' => $current['settings'],
        ], $h)->assertOk();

        $this->assertSame([], (new CpiBookingService())->slotsForDate($day));
    }

    // ---- Articles ----

    public function test_article_publish_writes_static_page_and_unpublish_removes_it(): void
    {
        $h = $this->adminHeaders();
        File::ensureDirectoryExists(resource_path('cpi'));
        $this->assertFileExists(resource_path('cpi/article-shell.html'), 'Run python tools/cpi-site/build.py first.');

        $id = $this->postJson('/api/cpi-admin/articles', [
            'title' => 'Why file early', 'excerpt' => 'A short note', 'body_html' => '<p>Hello <b>world</b></p><script>alert(1)</script>',
        ], $h)->assertCreated()->json('id');

        $this->postJson("/api/cpi-admin/articles/{$id}/publish", [], $h)->assertOk();

        $page = File::get($this->siteDir.'/insights/why-file-early.html');
        $this->assertStringContainsString('Why file early', $page);
        $this->assertStringContainsString('<b>world</b>', $page);
        $this->assertStringNotContainsString('<script>alert', $page);
        $this->assertStringContainsString('why-file-early', File::get($this->siteDir.'/insights/index.json'));
        $this->assertStringContainsString('/insights/why-file-early', File::get($this->siteDir.'/insights/sitemap-insights.xml'));

        $this->postJson("/api/cpi-admin/articles/{$id}/unpublish", [], $h)->assertOk();
        $this->assertFileDoesNotExist($this->siteDir.'/insights/why-file-early.html');
        $this->assertStringNotContainsString('why-file-early', File::get($this->siteDir.'/insights/index.json'));
    }

    // ---- Article images ----

    public function test_image_upload_is_reencoded_and_stored_under_insights_img(): void
    {
        $h = $this->adminHeaders();

        $res = $this->post('/api/cpi-admin/images', ['image' => UploadedFile::fake()->image('photo.png', 900, 600)], $h + ['Accept' => 'application/json'])->assertCreated();

        $this->assertMatchesRegularExpression('#^/insights/img/[a-f0-9]{24}\.webp$#', $res->json('url'));
        $this->assertFileExists($this->siteDir.$res->json('url'));
    }

    public function test_fake_image_upload_is_rejected(): void
    {
        $h = $this->adminHeaders();
        $notImage = UploadedFile::fake()->createWithContent('evil.png', '<?php echo 1;');

        $this->post('/api/cpi-admin/images', ['image' => $notImage], $h + ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_article_keeps_only_own_uploaded_images_and_publishes_with_cover(): void
    {
        $h = $this->adminHeaders();
        $id = $this->postJson('/api/cpi-admin/articles', [
            'title' => 'With pictures',
            'body_html' => '<p>Intro text for the preview.</p><img src="https://evil.test/x.png" alt="bad"><img src="/insights/img/abcdef0123456789abcdef01.webp" alt="ok">',
            'cover_image' => '/insights/img/abcdef0123456789abcdef01.webp', 'cover_alt' => 'A cover',
        ], $h)->assertCreated()->json('id');

        $article = CpiArticle::find($id);
        $this->assertStringNotContainsString('evil.test', $article->body_html);
        $this->assertStringContainsString('/insights/img/abcdef0123456789abcdef01.webp', $article->body_html);
        $this->assertSame('Intro text for the preview.', $article->excerpt, 'A missing summary falls back to the body text.');

        $this->postJson("/api/cpi-admin/articles/{$id}/publish", [], $h)->assertOk();
        $page = File::get($this->siteDir.'/insights/with-pictures.html');
        $this->assertStringContainsString('cpi-article-cover', $page);
        $this->assertStringContainsString('content="https://canpatentit.com/insights/img/abcdef0123456789abcdef01.webp"', $page);
        $index = json_decode(File::get($this->siteDir.'/insights/index.json'), true);
        $this->assertSame('/insights/img/abcdef0123456789abcdef01.webp', $index[0]['cover']);
    }

    public function test_deleted_and_replaced_images_are_cleaned_up_but_shared_ones_stay(): void
    {
        $h = $this->adminHeaders();
        $up = fn () => $this->post('/api/cpi-admin/images', ['image' => UploadedFile::fake()->image('p'.uniqid().'.png', 300 + random_int(1, 200), 200)], $h + ['Accept' => 'application/json'])->json('url');
        [$a, $b, $shared] = [$up(), $up(), $up()];

        $first = $this->postJson('/api/cpi-admin/articles', ['title' => 'First article', 'body_html' => "<p>x</p><img src=\"{$b}\" alt=\"\"><img src=\"{$shared}\" alt=\"\">", 'cover_image' => $a], $h)->json('id');
        $second = $this->postJson('/api/cpi-admin/articles', ['title' => 'Second article', 'body_html' => "<p>y</p><img src=\"{$shared}\" alt=\"\">"], $h)->json('id');

        // Editing out the inline picture deletes it; the cover stays.
        $this->putJson("/api/cpi-admin/articles/{$first}", ['title' => 'First article', 'body_html' => "<p>x</p><img src=\"{$shared}\" alt=\"\">", 'cover_image' => $a], $h)->assertOk();
        $this->assertFileDoesNotExist($this->siteDir.$b);
        $this->assertFileExists($this->siteDir.$a);

        // Deleting the first article removes its cover but keeps the picture the second article still uses.
        $this->deleteJson("/api/cpi-admin/articles/{$first}", [], $h)->assertOk();
        $this->assertFileDoesNotExist($this->siteDir.$a);
        $this->assertFileExists($this->siteDir.$shared);

        $this->deleteJson("/api/cpi-admin/articles/{$second}", [], $h)->assertOk();
        $this->assertFileDoesNotExist($this->siteDir.$shared);
    }

    public function test_prune_command_removes_old_strays_only(): void
    {
        $dir = $this->siteDir.'/insights/img';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/oldstray.webp', 'x');
        touch($dir.'/oldstray.webp', time() - 7200);
        File::put($dir.'/freshstray.webp', 'x');

        $this->artisan('cpi:prune-images')->assertSuccessful();

        $this->assertFileDoesNotExist($dir.'/oldstray.webp');
        $this->assertFileExists($dir.'/freshstray.webp');
    }

    // ---- FAQ ----

    public function test_faq_can_be_added_edited_reordered_hidden_and_deleted(): void
    {
        $h = $this->adminHeaders();
        $this->assertSame(13, \App\Models\CpiFaq::whereNull('page')->count(), 'The migration seeds the starting questions.');
        $this->assertSame(17, \App\Models\CpiFaq::whereNotNull('page')->count(), 'And the per-page common questions.');
        \App\Models\CpiFaq::whereNull('page')->delete();

        $a = $this->postJson('/api/cpi-admin/faqs', ['question' => 'First question?', 'answer' => "Line one.\n\nLine two <b>x</b>"], $h)->assertCreated()->json('id');
        $b = $this->postJson('/api/cpi-admin/faqs', ['question' => 'Second question?', 'answer' => 'Second answer.'], $h)->assertCreated()->json('id');

        $page = File::get($this->siteDir.'/faq.html');
        $this->assertStringContainsString('First question?', $page);
        $this->assertStringContainsString('<p>Line one.</p><p>Line two &lt;b&gt;x&lt;/b&gt;</p>', $page, 'Answers are escaped and split into paragraphs.');
        $this->assertStringContainsString('"@type":"FAQPage"', $page);
        $this->assertLessThan(strpos($page, 'Second question?'), strpos($page, 'First question?'));

        $this->putJson("/api/cpi-admin/faqs/{$a}", ['question' => 'First question, edited?', 'answer' => 'New answer.'], $h)->assertOk();
        $this->assertStringContainsString('First question, edited?', File::get($this->siteDir.'/faq.html'));

        $this->putJson('/api/cpi-admin/faqs-order', ['ids' => [$b, $a]], $h)->assertOk();
        $page = File::get($this->siteDir.'/faq.html');
        $this->assertLessThan(strpos($page, 'First question, edited?'), strpos($page, 'Second question?'));

        $this->putJson("/api/cpi-admin/faqs/{$b}", ['question' => 'Second question?', 'answer' => 'Second answer.', 'active' => false], $h)->assertOk();
        $this->assertStringNotContainsString('Second question?', File::get($this->siteDir.'/faq.html'));

        $this->deleteJson("/api/cpi-admin/faqs/{$a}", [], $h)->assertOk();
        $this->assertStringNotContainsString('First question', File::get($this->siteDir.'/faq.html'));
    }

    public function test_common_questions_on_a_service_page_can_be_managed_without_touching_other_pages(): void
    {
        $h = $this->adminHeaders();
        File::ensureDirectoryExists($this->siteDir.'/services');
        File::ensureDirectoryExists($this->siteDir.'/technology');
        $tpl = '<main><!--cpi:faq--><div class="old">default</div><!--/cpi:faq--><p>rest</p></main><head><!--cpi:faqld--><!--/cpi:faqld--></head>';
        File::put($this->siteDir.'/services/patentability-prior-art.html', $tpl);
        File::put($this->siteDir.'/technology/climate-energy.html', $tpl);

        $this->getJson('/api/cpi-admin/faqs-pages', $h)->assertOk()->assertJsonCount(10);
        $this->assertCount(3, $this->getJson('/api/cpi-admin/faqs?page=patentability-prior-art', $h)->assertOk()->json());

        $id = $this->postJson('/api/cpi-admin/faqs', ['page' => 'patentability-prior-art', 'question' => 'Does it work <i>here</i>?', 'answer' => 'Yes.'], $h)->assertCreated()->json('id');
        $svc = File::get($this->siteDir.'/services/patentability-prior-art.html');
        $this->assertStringContainsString('Does it work &lt;i&gt;here&lt;/i&gt;?', $svc);
        $this->assertStringContainsString('<h2>Common questions</h2>', $svc);
        $this->assertStringContainsString('"@type":"FAQPage"', $svc);
        $this->assertStringContainsString('<p>rest</p>', $svc, 'The rest of the page is untouched.');
        $this->assertStringContainsString('class="old"', File::get($this->siteDir.'/technology/climate-energy.html'), 'Other pages are not rewritten.');

        $this->putJson("/api/cpi-admin/faqs/{$id}", ['question' => 'Edited here?', 'answer' => 'Still yes.'], $h)->assertOk();
        $this->assertStringContainsString('Edited here?', File::get($this->siteDir.'/services/patentability-prior-art.html'));

        // Deleting every question leaves no empty heading and no FAQ markup.
        foreach ($this->getJson('/api/cpi-admin/faqs?page=patentability-prior-art', $h)->json() as $row) {
            $this->deleteJson('/api/cpi-admin/faqs/'.$row['id'], [], $h)->assertOk();
        }
        $svc = File::get($this->siteDir.'/services/patentability-prior-art.html');
        $this->assertStringNotContainsString('Common questions', $svc);
        $this->assertStringNotContainsString('FAQPage', $svc);
        $this->assertStringContainsString('<p>rest</p>', $svc);
    }

    public function test_faq_page_must_be_a_known_page_and_reorder_is_scoped(): void
    {
        $h = $this->adminHeaders();

        $this->postJson('/api/cpi-admin/faqs', ['page' => '../../etc/passwd', 'question' => 'Bad page?', 'answer' => 'No.'], $h)->assertStatus(422);

        $mainId = \App\Models\CpiFaq::whereNull('page')->orderBy('sort_order')->value('id');
        $pageIds = \App\Models\CpiFaq::where('page', 'climate-energy')->orderBy('sort_order')->pluck('id')->all();
        $before = \App\Models\CpiFaq::find($mainId)->sort_order;

        // A main-page id sent in another page's order list is ignored.
        $this->putJson('/api/cpi-admin/faqs-order', ['page' => 'climate-energy', 'ids' => array_merge([$mainId], $pageIds)], $h)->assertOk();
        $this->assertSame($before, \App\Models\CpiFaq::find($mainId)->sort_order);
    }

    public function test_faq_endpoints_require_admin_and_validate(): void
    {
        $this->getJson('/api/cpi-admin/faqs')->assertUnauthorized();
        $h = $this->adminHeaders();

        $this->postJson('/api/cpi-admin/faqs', ['question' => '', 'answer' => ''], $h)->assertStatus(422);
    }

    public function test_republish_command_rewrites_faq_and_published_articles(): void
    {
        $h = $this->adminHeaders();
        $id = $this->postJson('/api/cpi-admin/articles', ['title' => 'Republish me', 'body_html' => '<p>Body text here.</p>'], $h)->json('id');
        $this->postJson("/api/cpi-admin/articles/{$id}/publish", [], $h)->assertOk();
        File::delete($this->siteDir.'/insights/republish-me.html');
        File::delete($this->siteDir.'/faq.html');

        $this->artisan('cpi:republish')->assertSuccessful();

        $this->assertFileExists($this->siteDir.'/insights/republish-me.html');
        $this->assertFileExists($this->siteDir.'/faq.html');
    }

    public function test_cover_image_must_be_an_uploaded_path(): void
    {
        $h = $this->adminHeaders();

        $this->postJson('/api/cpi-admin/articles', ['title' => 'x y z', 'body_html' => '<p>a</p>', 'cover_image' => 'https://evil.test/a.png'], $h)->assertStatus(422);
    }

    public function test_article_endpoints_require_admin_token(): void
    {
        $this->getJson('/api/cpi-admin/articles')->assertUnauthorized();
        $this->postJson('/api/cpi-admin/articles', ['title' => 'x', 'body_html' => 'y'])->assertUnauthorized();
    }
}
