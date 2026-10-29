<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Gate;

use Itsjustmoha\X402\Core\Codec\HeaderCodec;
use Itsjustmoha\X402\Core\Exception\FacilitatorUnavailable;
use Itsjustmoha\X402\Core\Exception\InvalidPaymentHeader;
use Itsjustmoha\X402\Core\Facilitator\FacilitatorClient;
use Itsjustmoha\X402\Core\Protocol\LegacyNetworks;
use Itsjustmoha\X402\Core\Protocol\PaymentPayload;
use Itsjustmoha\X402\Core\Protocol\PaymentRequired;
use Itsjustmoha\X402\Core\Protocol\PaymentRequirements;
use Itsjustmoha\X402\Core\Protocol\ProtocolVersion;

/**
 * Framework-free decision logic of the x402 "authorization" payment flow
 * (x402 v2 specification section 6.1: verify -> resource -> settle -> respond),
 * mirroring the reference x402HTTPResourceServer:
 *
 *  evaluate()  no payment            -> Challenge (402 + PAYMENT-REQUIRED)
 *              bad/invalid payment   -> Rejected  (402 + PAYMENT-REQUIRED with error)
 *              facilitator down      -> Rejected  (502)
 *              valid payment         -> Verified  (run the handler now)
 *  settle()    after a 2xx handler   -> Settled   (attach PAYMENT-RESPONSE)
 *                                    -> SettlementFailed (402 or 502, drop the handler output)
 *  abandon()   handler failed        -> nothing is charged, lock released
 */
final readonly class PaymentGate
{
    /** Error text of the reference server for a request without payment. */
    public const string PAYMENT_REQUIRED = 'Payment required';

    /** Error text of the reference server when `accepted` matches none of our requirements. */
    public const string NO_MATCHING_REQUIREMENTS = 'No matching payment requirements';

    /** The same signed payment is already being processed (or was settled moments ago). */
    public const string DUPLICATE_PAYMENT = 'duplicate_payment';

    public const string FACILITATOR_UNAVAILABLE = 'facilitator_unavailable';

    private const string LOCK_PREFIX = 'x402:payment:';

    /** The lock outlives the authorization window so a settled payload cannot be replayed meanwhile. */
    private const int LOCK_GRACE_SECONDS = 60;

    private VersionNegotiator $negotiator;

    public function __construct(
        private FacilitatorClient $facilitator,
        private PaymentLock $lock,
        private bool $acceptV1 = false,
    ) {
        $this->negotiator = new VersionNegotiator($acceptV1);
    }

    public function evaluate(PaymentRequired $offer, RequestContext $request): Challenge|Verified|Rejected
    {
        try {
            $payment = $this->negotiator->paymentFrom($request);
        } catch (InvalidPaymentHeader $exception) {
            return $this->reject($offer, $exception->errorCode, null);
        }

        if ($payment === null) {
            $required = $offer->withError(self::PAYMENT_REQUIRED);

            return new Challenge($required, $this->paymentRequiredReply($required, null));
        }

        $requirements = $this->findMatch($offer, $payment);

        if ($requirements === null) {
            return $this->reject($offer, self::NO_MATCHING_REQUIREMENTS, $payment->version);
        }

        $lockKey = self::LOCK_PREFIX.$payment->fingerprint();
        $lockOwner = $this->lock->acquire($lockKey, $requirements->maxTimeoutSeconds + self::LOCK_GRACE_SECONDS);

        if ($lockOwner === null) {
            return $this->reject($offer, self::DUPLICATE_PAYMENT, $payment->version, $requirements, $payment->claimedPayer());
        }

        try {
            $verification = $this->facilitator->verify($payment, $this->wireRequirements($offer, $requirements, $payment->version));
        } catch (FacilitatorUnavailable) {
            $this->lock->release($lockKey, $lockOwner);

            return new Rejected(self::FACILITATOR_UNAVAILABLE, self::unavailableReply(), $requirements, $payment->claimedPayer());
        }

        if (! $verification->isValid) {
            $this->lock->release($lockKey, $lockOwner);

            return $this->reject(
                $offer,
                $verification->invalidReason ?? 'invalid_payment',
                $payment->version,
                $requirements,
                $verification->payer ?? $payment->claimedPayer(),
            );
        }

        return new Verified($offer, $requirements, $payment, $verification, $lockKey, $lockOwner);
    }

    /**
     * Call only after the protected handler produced a successful (2xx) response.
     */
    public function settle(Verified $verified): Settled|SettlementFailed
    {
        $version = $verified->payment->version;
        $wire = $this->wireRequirements($verified->offer, $verified->requirements, $version);

        try {
            $settlement = $this->facilitator->settle($verified->payment, $wire);

            if ($settlement->isPending()) {
                // Same as the reference server: exactly one retry with the identical
                // payload lets the facilitator reconcile the broadcast transaction.
                $settlement = $this->facilitator->settle($verified->payment, $wire);
            }
        } catch (FacilitatorUnavailable) {
            // Outcome unknown (the transfer may have happened): keep the lock until it
            // expires so this authorization cannot unlock the resource a second time.
            return new SettlementFailed(self::FACILITATOR_UNAVAILABLE, self::unavailableReply(), null);
        }

        $encoded = HeaderCodec::encode($settlement->toArray());

        if ($settlement->success) {
            return new Settled($settlement, $verified->requirements, [$version->responseHeader() => $encoded]);
        }

        if (! $settlement->isPending()) {
            $this->lock->release($verified->lockKey, $verified->lockOwner);
        }

        $reason = $settlement->errorReason ?? 'unexpected_settle_error';
        $reply = $this->paymentRequiredReply($verified->offer->withError($reason), $version);

        return new SettlementFailed(
            $reason,
            new HttpReply($reply->status, $reply->headers + [$version->responseHeader() => $encoded], $reply->body),
            $settlement,
        );
    }

    /**
     * The handler failed or returned a non-2xx response: nothing is settled and
     * the client may retry with the same signed payment.
     */
    public function abandon(Verified $verified): void
    {
        $this->lock->release($verified->lockKey, $verified->lockOwner);
    }

    private function findMatch(PaymentRequired $offer, PaymentPayload $payment): ?PaymentRequirements
    {
        foreach ($offer->accepts as $requirements) {
            $matches = $payment->version === ProtocolVersion::V2
                ? $requirements->isMatchedBy($payment->accepted)
                : $requirements->scheme === $payment->scheme && LegacyNetworks::nameFor($requirements->network) === $payment->network;

            if ($matches) {
                return $requirements;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function wireRequirements(PaymentRequired $offer, PaymentRequirements $requirements, ProtocolVersion $version): array
    {
        return $version === ProtocolVersion::V1
            ? ($offer->v1RequirementsFor($requirements) ?? $requirements->toArray())
            : $requirements->toArray();
    }

    private function reject(
        PaymentRequired $offer,
        string $reason,
        ?ProtocolVersion $clientVersion,
        ?PaymentRequirements $requirements = null,
        ?string $payer = null,
    ): Rejected {
        return new Rejected($reason, $this->paymentRequiredReply($offer->withError($reason), $clientVersion), $requirements, $payer);
    }

    /**
     * 402 with the v2 PaymentRequired in the PAYMENT-REQUIRED header. The JSON
     * body repeats it for clients and agents that only read bodies; with v1
     * compatibility on, the body uses the v1 format so legacy clients can pay
     * (v2 clients read the header first).
     */
    private function paymentRequiredReply(PaymentRequired $required, ?ProtocolVersion $clientVersion): HttpReply
    {
        $v2 = $required->toArray();
        $wantsV1 = $clientVersion === ProtocolVersion::V1 || ($clientVersion === null && $this->acceptV1);

        return new HttpReply(
            402,
            [
                ProtocolVersion::PAYMENT_REQUIRED_HEADER => HeaderCodec::encode($v2),
                'Cache-Control' => 'no-store',
            ],
            ($wantsV1 ? $required->toV1Array() : null) ?? $v2,
        );
    }

    private static function unavailableReply(): HttpReply
    {
        return new HttpReply(502, ['Cache-Control' => 'no-store'], ['error' => self::FACILITATOR_UNAVAILABLE]);
    }
}
