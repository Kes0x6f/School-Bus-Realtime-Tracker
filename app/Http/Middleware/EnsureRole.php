<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Usage: ->middleware('role:driver')
     *        ->middleware('role:student,admin')   ← OR logic (any of these roles passes)
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!Auth::check() || !in_array(Auth::user()->role, $roles)) {
            abort(403, 'Unauthorized.');
        }

        return $next($request);
    }
}