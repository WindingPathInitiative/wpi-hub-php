<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fire-and-forget audit logging. Ports wpi-hub helpers/audit.js.
 *
 * Short-circuits (no-op) when the host is "bypass" or the env is testing. A
 * reference may be an Eloquent model (serialized to {service, object, objectId})
 * or a plain array. Unlike the Node version, a transport failure here is logged
 * but NOT fatal to the request.
 */
class AuditService
{
    public function log(string $message, mixed $refs = [], array $metadata = [], mixed $delta = ''): void
    {
        $host = config('audit.host');

        if ($host === 'bypass' || app()->environment('testing') || empty($host)) {
            return;
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            return; // Node rejected here; we simply skip (nothing to attribute).
        }

        $body = [
            'userId' => $user->id,
            'service' => 'user-hub-v'.explode('.', (string) config('wpi.version'))[0],
            'message' => $message,
            'occurredAt' => (int) (microtime(true) * 1000),
        ];

        if (! empty($metadata['office'])) {
            $body['officeId'] = is_array($metadata['office'])
                ? ($metadata['office']['id'] ?? null)
                : $metadata['office']->id;
        }
        if ($metadata !== []) {
            $body['metadata'] = $metadata;
        }
        if (! empty($delta)) {
            $body['delta'] = is_string($delta) ? $delta : json_encode($delta);
        }

        $refs = is_array($refs) && ! $this->isAssoc($refs) ? $refs : [$refs];
        $body['references'] = array_map(fn ($ref) => $ref instanceof Model ? [
            'service' => 'user-hub',
            'object' => $ref->getTable(),
            'objectId' => $ref->getKey(),
        ] : $ref, $refs);

        try {
            Http::asJson()->post($host, $body);
        } catch (\Throwable $e) {
            Log::warning('Audit POST failed', ['message' => $message, 'error' => $e->getMessage()]);
        }
    }

    private function isAssoc(array $a): bool
    {
        return $a !== [] && array_keys($a) !== range(0, count($a) - 1);
    }
}
