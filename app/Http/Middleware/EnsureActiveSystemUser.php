<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rechaza tokens cuyo propietario dejó de ser una cuenta activa del sistema. */
class EnsureActiveSystemUser
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isActive()) {
            return new JsonResponse([
                'message' => 'El usuario se encuentra inactivo y no puede acceder al sistema.',
                'code' => 'user_inactive',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
