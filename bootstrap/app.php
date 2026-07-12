<?php

use App\Exceptions\InvalidTournamentStructure;
use App\Exceptions\StaleResultException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? new JsonResponse(['message' => $e->getMessage()], 401)
            : null);
            
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $previous = $e->getPrevious();

            return new JsonResponse([
                'message' => $e->getMessage(),
                'reason' => $previous instanceof AuthorizationException
                    ? $previous->response()?->code()
                    : null,
            ], 403);
        });

        $exceptions->render(fn (StaleResultException $e) => new JsonResponse([
            'message' => $e->getMessage(),
            'expected_version' => $e->expectedVersion,
        ], 409));

        $exceptions->render(fn (InvalidTournamentStructure $e) => new JsonResponse([
            'message' => $e->getMessage(),
        ], 422));
    })->create();
