<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyFamily
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $familyId = $request->header('X-Family-Id');

        if (! $familyId || ! Str::isUuid($familyId)) {
            abort(400, 'Family ID is required.');
        }
        
        return $next($request);
    }
}
