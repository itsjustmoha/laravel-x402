<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;
use Itsjustmoha\X402\Core\Exception\InvalidPrice;
use Itsjustmoha\X402\Core\Gate\BasicRequest;
use Itsjustmoha\X402\Core\Gate\Challenge;
use Itsjustmoha\X402\Core\Gate\HttpReply;
use Itsjustmoha\X402\Core\Gate\PaymentGate;
use Itsjustmoha\X402\Core\Gate\Rejected;
use Itsjustmoha\X402\Core\Gate\SettlementFailed;
use Itsjustmoha\X402\Core\Gate\Verified;
use Itsjustmoha\X402\Laravel\Events\PaymentFailed;
use Itsjustmoha\X402\Laravel\Events\PaymentSettled;
use Itsjustmoha\X402\Laravel\Events\PaymentVerified;
use Itsjustmoha\X402\Laravel\Routing\PaidRouteResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Route middleware "x402": answers 402 until a valid x402 payment is
 * presented, runs the route, and settles the payment only when the route
 * returned a 2xx response.
 *
 * Usage: Route::get(...)->paid('$0.01') or ->middleware('x402:0.01').
 */
final readonly class RequirePayment
{
    public function __construct(
        private PaymentGate $gate,
        private PaidRouteResolver $resolver,
        private Dispatcher $events,
        private LoggerInterface $logger,
        private ExceptionHandler $exceptions,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws InvalidConfiguration
     * @throws InvalidPrice
     */
    public function handle(Request $request, Closure $next, string ...$parameters): Response
    {
        $offer = $this->resolver->resolve($request, array_values($parameters));
        $decision = $this->gate->evaluate($offer, new BasicRequest($request->headers->all()));

        if ($decision instanceof Challenge) {
            return self::toResponse($decision->reply);
        }

        if ($decision instanceof Rejected) {
            $this->failed($request, PaymentFailed::STAGE_VERIFY, $decision->reason, $decision->reply->status, $decision);

            return self::toResponse($decision->reply);
        }

        try {
            $this->events->dispatch(new PaymentVerified(
                $request->method(),
                $request->path(),
                $decision->requirements,
                $decision->payer(),
                $decision->payment->version,
            ));

            $response = $next($request);
        } catch (Throwable $exception) {
            $this->gate->abandon($decision);

            throw $exception;
        }

        if (! $response->isSuccessful()) {
            // Mirrors the reference middleware: a failed handler is never charged.
            $this->gate->abandon($decision);

            return $response;
        }

        $result = $this->gate->settle($decision);

        if ($result instanceof SettlementFailed) {
            $this->failed($request, PaymentFailed::STAGE_SETTLE, $result->reason, $result->reply->status, $decision, $result->settlement?->transaction);

            return self::toResponse($result->reply);
        }

        foreach ($result->headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        // Settlement metadata is per payer: shared caches must not store it.
        $response->setPrivate();

        try {
            $this->events->dispatch(new PaymentSettled(
                $request->method(),
                $request->path(),
                $result->requirements,
                $result->settlement,
                $decision->payment->version,
            ));
        } catch (Throwable $exception) {
            // The payer has been charged: a failing listener must not cost them the response.
            $this->exceptions->report($exception);
        }

        $this->logger->info('x402 payment settled', [
            'path' => $request->path(),
            'network' => $result->settlement->network,
            'amount' => $result->requirements->amount,
            'asset' => $result->requirements->asset,
            'transaction' => $result->settlement->transaction,
        ]);

        return $response;
    }

    /**
     * @param  PaymentFailed::STAGE_*  $stage
     */
    private function failed(Request $request, string $stage, string $reason, int $status, Rejected|Verified $context, ?string $transaction = null): void
    {
        $requirements = $context->requirements;
        $payer = $context instanceof Verified ? $context->payer() : $context->payer;

        $this->events->dispatch(new PaymentFailed($request->method(), $request->path(), $stage, $reason, $status, $requirements, $payer));

        // Payer addresses are deliberately not logged (pseudonymous personal data).
        $this->logger->log($status >= 500 ? 'error' : 'info', 'x402 payment failed', [
            'path' => $request->path(),
            'stage' => $stage,
            'reason' => $reason,
            'status' => $status,
            'network' => $requirements?->network,
            'amount' => $requirements?->amount,
            'transaction' => $transaction,
        ]);
    }

    private static function toResponse(HttpReply $reply): JsonResponse
    {
        return new JsonResponse($reply->body, $reply->status, $reply->headers, JSON_UNESCAPED_SLASHES);
    }
}
