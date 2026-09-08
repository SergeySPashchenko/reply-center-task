<?php

namespace App\Jobs;

use App\Models\CampaignEnrollment;
use App\Models\Client;
use App\Models\ReplyTask;
use App\Services\SentimentLabelResolver;
use App\Support\IdempotencyGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Consumes reply.received events from the bus.
 */
class ProcessInboundReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array $payload decoded reply.received event
     */
    public function __construct(
        public array $payload,
    ) {}

    public function handle(IdempotencyGuard $guard, SentimentLabelResolver $resolver): void
    {
        $tenantId = (int) ($this->payload['tenant_id'] ?? 0);
        $eventId  = (string) ($this->payload['event_id'] ?? '');
        $sender   = strtolower(trim((string) ($this->payload['sender'] ?? '')));

        if ($tenantId === 0 || $eventId === '' || $sender === '') {
            return;
        }

        if ($guard->alreadyProcessed($tenantId, $eventId)) {
            return;
        }

        $client = Client::query()
            ->where('tenant_id', $tenantId)
            ->where('email', $sender)
            ->first();

        if ($client === null) {
            $guard->claim($tenantId, $eventId);

            return;
        }

        $body = $this->extractBody($this->payload);
        $sentiment = $resolver->resolve($body);

        DB::transaction(function () use ($guard, $tenantId, $eventId, $client, $body, $sentiment) {
            if (! $guard->claim($tenantId, $eventId)) {
                return;
            }

            ReplyTask::create([
                'tenant_id' => $tenantId,
                'client_id' => $client->id,
                'event_id'  => $eventId,
                'sentiment' => $sentiment,
                'body'      => $body !== '' ? $body : null,
                'status'    => 'open',
            ]);

            if ($sentiment === 'unsubscribe') {
                $this->suppressClient($client);
            }
        });
    }

    private function extractBody(array $payload): string
    {
        $plain = trim((string) ($payload['body_plain'] ?? ''));

        if ($plain !== '') {
            return $plain;
        }

        $html = (string) ($payload['body_html'] ?? '');

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }

    private function suppressClient(Client $client): void
    {
        if ($client->suppressed_at === null) {
            $client->forceFill(['suppressed_at' => now()])->save();
        }

        CampaignEnrollment::query()
            ->where('tenant_id', $client->tenant_id)
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->update([
                'status'       => 'stopped',
                'next_send_at' => null,
                'updated_at'   => now(),
            ]);
    }
}
