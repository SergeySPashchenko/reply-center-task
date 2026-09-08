<?php

namespace App\Services;

use App\Contracts\SentimentClassifier;
use App\Exceptions\ClassifierTimeoutException;
use Throwable;

/**
 * Turns the raw classifier response into a rubric label or null.
 *
 * Never throws: after retries are exhausted the caller gets null so the
 * job can still create a Reply Center task without aborting the queue.
 */
class SentimentLabelResolver
{
    public const LABELS = [
        'interested',
        'question',
        'not_now',
        'unsubscribe',
        'wrong_person',
        'auto_reply',
    ];

    public function __construct(
        private SentimentClassifier $classifier,
        private int $maxAttempts = 3,
        private int $retryDelayMs = 0,
    ) {}

    public function resolve(string $body): ?string
    {
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $label = $this->parse($this->classifier->classify($body));

                if ($label !== null) {
                    return $label;
                }
            } catch (ClassifierTimeoutException) {
                // retry
            } catch (Throwable) {
                // Malformed transport / unexpected errors — treat as soft fail and retry.
            }

            if ($attempt < $this->maxAttempts && $this->retryDelayMs > 0) {
                usleep($this->retryDelayMs * 1000);
            }
        }

        return null;
    }

    private function parse(string $raw): ?string
    {
        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! isset($decoded['sentiment']) || ! is_string($decoded['sentiment'])) {
            return null;
        }

        $label = $decoded['sentiment'];

        return in_array($label, self::LABELS, true) ? $label : null;
    }
}
