<?php

namespace Tests\Feature;

use App\Contracts\SentimentClassifier;
use App\Exceptions\ClassifierTimeoutException;
use App\Jobs\ProcessInboundReplyJob;
use App\Models\CampaignEnrollment;
use App\Models\Client;
use App\Models\ProcessedEvent;
use App\Models\ReplyTask;
use App\Services\SentimentLabelResolver;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessInboundReplyJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', config('database.default'));
    }

    public function test_creates_reply_task_with_classified_sentiment(): void
    {
        $this->bindClassifier(fn () => '{"sentiment":"question"}');

        $client = Client::factory()->create([
            'tenant_id' => 42,
            'email'     => 'm.tremblay@lakesideprop.ca',
        ]);
        CampaignEnrollment::factory()->create([
            'tenant_id' => 42,
            'client_id' => $client->id,
            'status'    => 'active',
        ]);

        ProcessInboundReplyJob::dispatchSync($this->event([
            'event_id'   => 'evt_test_happy',
            'sender'     => 'm.tremblay@lakesideprop.ca',
            'body_plain' => 'How much would it be for eight windows?',
        ]));

        $task = ReplyTask::query()->sole();
        $this->assertSame(42, $task->tenant_id);
        $this->assertSame($client->id, $task->client_id);
        $this->assertSame('evt_test_happy', $task->event_id);
        $this->assertSame('question', $task->sentiment);
        $this->assertSame('open', $task->status);
        $this->assertSame(1, ProcessedEvent::query()->count());
    }

    public function test_duplicate_event_creates_exactly_one_task(): void
    {
        $this->bindClassifier(fn () => '{"sentiment":"interested"}');

        $client = Client::factory()->create([
            'tenant_id' => 42,
            'email'     => 'd.walker@northshore-homes.ca',
        ]);
        CampaignEnrollment::factory()->create([
            'tenant_id' => 42,
            'client_id' => $client->id,
            'status'    => 'active',
        ]);

        $payload = $this->event([
            'event_id'   => 'evt_01HZ8A0001',
            'sender'     => 'd.walker@northshore-homes.ca',
            'body_plain' => 'Sounds good. Call me Thursday.',
        ]);

        ProcessInboundReplyJob::dispatchSync($payload);
        ProcessInboundReplyJob::dispatchSync($payload);

        $this->assertSame(1, ReplyTask::query()->count());
        $this->assertSame(1, ProcessedEvent::query()->count());
    }

    public function test_unsubscribe_suppresses_client_and_stops_enrollments(): void
    {
        $this->bindClassifier(fn () => '{"sentiment":"unsubscribe"}');

        $client = Client::factory()->create([
            'tenant_id' => 42,
            'email'     => 'r.osei@maplecourt.ca',
        ]);
        $enrollment = CampaignEnrollment::factory()->create([
            'tenant_id'    => 42,
            'client_id'    => $client->id,
            'status'       => 'active',
            'next_send_at' => now()->addDay(),
        ]);

        ProcessInboundReplyJob::dispatchSync($this->event([
            'event_id'   => 'evt_unsub',
            'sender'     => 'r.osei@maplecourt.ca',
            'body_plain' => 'Please take me off your list.',
        ]));

        $client->refresh();
        $enrollment->refresh();

        $this->assertNotNull($client->suppressed_at);
        $this->assertSame('stopped', $enrollment->status);
        $this->assertNull($enrollment->next_send_at);
        $this->assertSame('unsubscribe', ReplyTask::query()->sole()->sentiment);
    }

    public function test_classifier_failure_creates_task_with_null_sentiment_without_stopping_campaign(): void
    {
        $this->bindClassifier(function () {
            throw new ClassifierTimeoutException();
        });

        $client = Client::factory()->create([
            'tenant_id' => 42,
            'email'     => 'a.ferreira@stonegate.ca',
        ]);
        $enrollment = CampaignEnrollment::factory()->create([
            'tenant_id' => 42,
            'client_id' => $client->id,
            'status'    => 'active',
        ]);

        ProcessInboundReplyJob::dispatchSync($this->event([
            'event_id'   => 'evt_flaky',
            'sender'     => 'a.ferreira@stonegate.ca',
            'body_plain' => 'Thanks, office closed.',
        ]));

        $task = ReplyTask::query()->sole();
        $this->assertNull($task->sentiment);
        $this->assertSame('open', $task->status);

        $client->refresh();
        $enrollment->refresh();
        $this->assertNull($client->suppressed_at);
        $this->assertSame('active', $enrollment->status);
    }

    public function test_tenant_isolation_does_not_attach_other_tenants_client(): void
    {
        $this->bindClassifier(fn () => '{"sentiment":"interested"}');

        $tenant42 = Client::factory()->create([
            'tenant_id' => 42,
            'email'     => 'd.walker@northshore-homes.ca',
        ]);
        CampaignEnrollment::factory()->create([
            'tenant_id' => 42,
            'client_id' => $tenant42->id,
            'status'    => 'active',
        ]);

        $tenant43 = Client::factory()->create([
            'tenant_id' => 43,
            'email'     => 'd.walker@northshore-homes.ca',
        ]);
        CampaignEnrollment::factory()->create([
            'tenant_id' => 43,
            'client_id' => $tenant43->id,
            'status'    => 'active',
        ]);

        ProcessInboundReplyJob::dispatchSync($this->event([
            'event_id'   => 'evt_01HZ8A0011',
            'tenant_id'  => 43,
            'sender'     => 'd.walker@northshore-homes.ca',
            'body_plain' => 'Yes, we are interested.',
            'recipient'  => 'campaign+c43@mg.ourdomain.com',
        ]));

        $task = ReplyTask::query()->sole();
        $this->assertSame(43, $task->tenant_id);
        $this->assertSame($tenant43->id, $task->client_id);
        $this->assertNotSame($tenant42->id, $task->client_id);
    }

    public function test_client_without_enrollment_still_gets_task_and_can_unsubscribe(): void
    {
        $this->bindClassifier(fn () => '{"sentiment":"unsubscribe"}');

        $client = Client::factory()->create([
            'tenant_id' => 42,
            'email'     => 'p.novak@nofuture.ca',
        ]);

        ProcessInboundReplyJob::dispatchSync($this->event([
            'event_id'   => 'evt_no_enrollment',
            'sender'     => 'p.novak@nofuture.ca',
            'body_plain' => 'Please take me off your list, I do not want emails.',
        ]));

        $task = ReplyTask::query()->sole();
        $this->assertSame($client->id, $task->client_id);
        $this->assertSame('unsubscribe', $task->sentiment);

        $client->refresh();
        $this->assertNotNull($client->suppressed_at);
        $this->assertSame(1, ProcessedEvent::query()->count());
    }

    public function test_unknown_sender_claims_event_without_creating_task(): void
    {
        $this->bindClassifier(fn () => '{"sentiment":"auto_reply"}');

        ProcessInboundReplyJob::dispatchSync($this->event([
            'event_id'   => 'evt_bounce',
            'sender'     => 'MAILER-DAEMON@mg.ourdomain.com',
            'body_plain' => 'Delivery failed.',
        ]));

        $this->assertSame(0, ReplyTask::query()->count());
        $this->assertSame(1, ProcessedEvent::query()->count());
    }

    public function test_fixture_suite_produces_expected_counts(): void
    {
        $this->seed(DemoSeeder::class);

        // Deterministic labels for fixture run — avoid FakeFlaky non-determinism in asserts.
        $this->bindClassifier(function (string $body) {
            $text = mb_strtolower($body);
            if (str_contains($text, 'take me off') || str_contains($text, 'unsubscribe')) {
                return '{"sentiment":"unsubscribe"}';
            }
            if (str_contains($text, 'out of the office') || str_contains($text, 'automatic reply') || str_contains($text, 'office is closed')) {
                return '{"sentiment":"auto_reply"}';
            }
            if (str_contains($text, 'how much') || str_contains($text, '?')) {
                return '{"sentiment":"question"}';
            }
            if (str_contains($text, 'not this year') || str_contains($text, 'budget')) {
                return '{"sentiment":"not_now"}';
            }

            return '{"sentiment":"interested"}';
        });

        $events = json_decode(
            file_get_contents(base_path('tests/Fixtures/inbound_events.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($events as $event) {
            ProcessInboundReplyJob::dispatchSync($event);
        }

        $this->assertSame(11, ProcessedEvent::query()->count(), '11 unique event_ids claimed');
        $this->assertSame(1, ProcessedEvent::query()->where('event_id', 'evt_01HZ8A0001')->count());

        // No client for bounce / loop
        $this->assertSame(0, ReplyTask::query()->whereIn('event_id', [
            'evt_01HZ8A0007',
            'evt_01HZ8A0009',
        ])->count());

        // 11 unique - 2 without client = 9 tasks
        $this->assertSame(9, ReplyTask::query()->count());

        $rita = Client::query()
            ->where('tenant_id', 42)
            ->where('email', 'r.osei@maplecourt.ca')
            ->firstOrFail();
        $this->assertNotNull($rita->suppressed_at);
        $this->assertSame(
            0,
            CampaignEnrollment::query()
                ->where('client_id', $rita->id)
                ->where('status', 'active')
                ->count(),
        );

        $task43 = ReplyTask::query()->where('event_id', 'evt_01HZ8A0011')->sole();
        $this->assertSame(43, $task43->tenant_id);

        $htmlOnly = ReplyTask::query()->where('event_id', 'evt_01HZ8A0008')->sole();
        $this->assertStringContainsString('Not this year', (string) $htmlOnly->body);
        $this->assertSame('not_now', $htmlOnly->sentiment);
    }

    /**
     * @param  callable(string): string  $classify
     */
    private function bindClassifier(callable $classify): void
    {
        $classifier = new class($classify) implements SentimentClassifier {
            public function __construct(private $classify) {}

            public function classify(string $body): string
            {
                return ($this->classify)($body);
            }
        };

        $this->app->instance(SentimentClassifier::class, $classifier);
        $this->app->instance(
            SentimentLabelResolver::class,
            new SentimentLabelResolver($classifier, maxAttempts: 3, retryDelayMs: 0),
        );
    }

    private function event(array $overrides): array
    {
        return array_merge([
            'event_id'   => 'evt_default',
            'tenant_id'  => 42,
            'sender'     => 'someone@example.com',
            'recipient'  => 'campaign+c42@mg.ourdomain.com',
            'body_plain' => 'Hello',
            'headers'    => ['Auto-Submitted' => 'no'],
            'timestamp'  => 1756713600,
        ], $overrides);
    }
}
