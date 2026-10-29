<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Gate;

use Itsjustmoha\X402\Core\Exception\InvalidPaymentHeader;
use Itsjustmoha\X402\Core\Protocol\PaymentPayload;
use Itsjustmoha\X402\Core\Protocol\ProtocolVersion;

/**
 * Finds the client's payment header and decides which protocol version it speaks.
 *
 * PAYMENT-SIGNATURE (v2) wins over X-PAYMENT (v1). The version is taken from
 * the payload's x402Version, like the reference decoders do; v1 payloads are
 * only accepted when v1 compatibility is switched on.
 */
final readonly class VersionNegotiator
{
    public function __construct(private bool $acceptV1) {}

    /**
     * @return PaymentPayload|null null when the request carries no payment
     *
     * @throws InvalidPaymentHeader
     */
    public function paymentFrom(RequestContext $request): ?PaymentPayload
    {
        foreach ([ProtocolVersion::V2, ProtocolVersion::V1] as $headerVersion) {
            $header = $request->header($headerVersion->paymentHeader());

            if ($header === null || trim($header) === '') {
                continue;
            }

            $payment = PaymentPayload::fromHeader($header);

            if ($payment->version === ProtocolVersion::V1 && ! $this->acceptV1) {
                throw InvalidPaymentHeader::unsupportedVersion('x402 v1 payments are not accepted here; pay with x402 v2 (PAYMENT-SIGNATURE header).');
            }

            return $payment;
        }

        return null;
    }
}
