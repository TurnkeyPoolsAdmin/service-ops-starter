<?php

namespace App\Jobs;

use App\Models\InboundMessage;
use App\Services\AiScheduling\AiSchedulingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs an inbound SMS through the AI scheduling negotiation, off the webhook
 * request. Queued deliberately: classifying a reply can take a 30-second
 * round trip to Anthropic, and a provider webhook that blocks (or 500s on a
 * timeout) gets retried by the provider — which would re-deliver the same
 * message and, worse, make our own slowness look like a delivery failure.
 *
 * Carries only the row id, never the model, so a retry always reads current
 * state rather than a serialized snapshot of it.
 *
 * A technician takes priority over a customer when one phone somehow matches
 * both: the tech is the one we have an open question with.
 */
class ProcessAiSchedulingReply implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $inboundMessageId) {}

    public function handle(AiSchedulingService $service): void
    {
        $inbound = InboundMessage::find($this->inboundMessageId);

        if ($inbound === null) {
            return;
        }

        $tech = $inbound->employee;
        if ($tech !== null) {
            $service->handleTechReply($tech, $inbound);

            return;
        }

        $customer = $inbound->customer;
        if ($customer !== null) {
            $service->handleCustomerReply($customer, $inbound);
        }
    }
}
