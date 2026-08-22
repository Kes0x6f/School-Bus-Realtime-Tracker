<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (!$user || $user->is_active) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() || $request->is('broadcasting/auth')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Your account has been deactivated.',
            ], 403);
        }

        return redirect()->route('login')
            ->withErrors(['email' => 'Your account has been deactivated.']);
    }
}
