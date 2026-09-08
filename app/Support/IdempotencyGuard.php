<?php

namespace App\Support;

use App\Models\ProcessedEvent;
use Illuminate\Support\Facades\DB;

/**
 * Guards against duplicate processing of bus events.
 *
 * The bus delivers at-least-once, so every consumer must be idempotent.
 * Deduplication is enforced by a unique index on (tenant_id, event_id);
 * claim() uses INSERT … ON CONFLICT DO NOTHING so concurrent workers
 * never put the Postgres transaction into an aborted state.
 */
class IdempotencyGuard
{
    public function alreadyProcessed(int $tenantId, string $eventId): bool
    {
        return ProcessedEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('event_id', $eventId)
            ->exists();
    }

    /**
     * Attempt to claim exclusive ownership of an event.
     *
     * @return bool true if this caller won the claim
     */
    public function claim(int $tenantId, string $eventId): bool
    {
        $now = now();

        $affected = DB::table('processed_events')->insertOrIgnore([
            'tenant_id'  => $tenantId,
            'event_id'   => $eventId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $affected === 1;
    }

    public function markProcessed(int $tenantId, string $eventId): void
    {
        $this->claim($tenantId, $eventId);
    }
}
