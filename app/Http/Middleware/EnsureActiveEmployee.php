<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveEmployee
{
    public function handle(Request $request, Closure $next): Response
    {
        $employee = $request->user();

        abort_unless(
            $employee instanceof Employee && $employee->active && $employee->can_login,
            403,
        );

        return $next($request);
    }
}
