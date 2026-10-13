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
