<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        $user = $request->user();
        $family = $user?->families()->where('families.id', $familyId)->first();

        if (! $family) {
            abort(403, 'You are not a member of this family.');
        }

        tenancy()->initialize($family);

        return $next($request);
    }
}
