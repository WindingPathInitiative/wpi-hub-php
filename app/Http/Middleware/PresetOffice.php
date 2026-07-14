<?php

namespace App\Http\Middleware;

use App\Support\PresetOfficeContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Captures the "preset office" from ?useOffice or body.useOffice into the
 * request-scoped PresetOfficeContext. Ports helpers/permissions.js presetOffice
 * (which Node registered as global middleware).
 */
class PresetOffice
{
    public function __construct(private readonly PresetOfficeContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $useOffice = $request->input('useOffice');

        if ($useOffice !== null && is_numeric($useOffice)) {
            $this->context->set((int) $useOffice);
        }

        return $next($request);
    }
}
