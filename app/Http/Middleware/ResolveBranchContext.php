<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\DomainInvariantException;
use App\Services\Authorization\BranchContextResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware phân giải và xác thực ngữ cảnh chi nhánh theo từng request (alias: `branch.context`).
 *
 * Flow:
 * Incoming Request -> ResolveBranchContext::handle()
 * -> BranchContextResolver::resolveFromRequest()
 * -> Gắn `BranchContext` bất biến vào `$request->attributes` -> Controller/Action tiếp theo.
 */
class ResolveBranchContext
{
    public function __construct(
        private readonly BranchContextResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $context = $this->resolver->resolveFromRequest($request);
        } catch (DomainInvariantException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => $exception->invariantCode,
                ], $exception->statusCode);
            }

            $statusCode = in_array($exception->statusCode, [401, 403, 404], true)
                ? $exception->statusCode
                : 403;

            if ($statusCode === 401) {
                return redirect()->guest(route('login'));
            }

            return abort($statusCode, $exception->getMessage());
        }

        $this->resolver->attachToRequest($request, $context);

        return $next($request);
    }
}
