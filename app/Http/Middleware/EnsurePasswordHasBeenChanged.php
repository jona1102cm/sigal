<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Impide operar módulos mientras la identidad conserve una contraseña temporal. */
class EnsurePasswordHasBeenChanged
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();

        if ($user?->must_change_password) {
            return new JsonResponse([
                'message' => 'Debe cambiar su contraseña temporal antes de continuar.',
                'code' => 'password_change_required',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
