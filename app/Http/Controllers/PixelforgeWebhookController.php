<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\GenerationUpdater;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature, GenerationUpdater $updater): Response
    {
        // Verify against the raw body, before decoding anything.
        $payload = $request->getContent();

        if (! $signature->isValid($payload, $request->header('Pixelforge-Signature'))) {
            return response('Invalid signature.', 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            return response('Malformed event.', 400);
        }

        $object = $event['data']['object'] ?? null;

        // One transaction: if handling fails, the event isn't recorded either, so Pixelforge's retry
        // gets a fresh chance. If it succeeds, the unique event_id turns every redelivery into a no-op.
        DB::transaction(function () use ($event, $object, $updater) {
            $isNew = WebhookEvent::insertOrIgnore([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'pixelforge_generation_id' => $object['id'] ?? null,
                'payload' => json_encode($event),
                'created_at' => now(),
            ]) === 1;

            if (! $isNew || ! str_starts_with($event['type'], 'generation.') || ! $object) {
                return;
            }

            if ($generation = $this->findGeneration($object)) {
                $updater->apply($generation, $object);
            }
        });

        return response()->noContent();
    }

    /**
     * Match by our own id in metadata first: the webhook can arrive before our POST has returned,
     * when we don't know Pixelforge's id yet.
     */
    private function findGeneration(array $object): ?Generation
    {
        $ourId = $object['metadata']['generation_id'] ?? null;

        return ($ourId ? Generation::find($ourId) : null)
            ?? Generation::where('pixelforge_id', $object['id'])->first();
    }
}
