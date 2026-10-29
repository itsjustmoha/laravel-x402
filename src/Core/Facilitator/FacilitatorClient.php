<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Facilitator;

use Itsjustmoha\X402\Core\Codec\Json;
use Itsjustmoha\X402\Core\Exception\FacilitatorUnavailable;
use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;
use Itsjustmoha\X402\Core\Exception\TransportException;
use Itsjustmoha\X402\Core\Http\HttpRequest;
use Itsjustmoha\X402\Core\Http\HttpResponse;
use Itsjustmoha\X402\Core\Http\HttpTransport;
use Itsjustmoha\X402\Core\Protocol\PaymentPayload;

/**
 * Client for the facilitator REST API: POST /verify, POST /settle and
 * GET /supported (x402 v2 specification section 7).
 *
 * Error policy:
 *  - a well-formed verify/settle body is always returned as a result, even with
 *    a 4xx status (it is then forced to "invalid"/"failed");
 *  - everything else (network error, timeout, 5xx/4xx without a protocol body,
 *    malformed JSON, cross-origin redirect) throws FacilitatorUnavailable.
 */
final readonly class FacilitatorClient
{
    private const int MAX_REDIRECTS = 3;

    private const string USER_AGENT = 'itsjustmoha-laravel-x402';

    public string $baseUrl;

    /**
     * @throws InvalidConfiguration
     */
    public function __construct(
        private HttpTransport $transport,
        string $baseUrl,
        private ?AuthHeadersProvider $auth = null,
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);

        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ! isset($parts['host'])) {
            throw new InvalidConfiguration(sprintf('Facilitator URL "%s" must be an absolute http(s) URL.', $baseUrl));
        }

        $this->baseUrl = $baseUrl;
    }

    /**
     * @param  array<string, mixed>  $requirements  requirements in the wire format of the payload's version
     *
     * @throws FacilitatorUnavailable
     */
    public function verify(PaymentPayload $payment, array $requirements): VerifyResponse
    {
        $response = $this->post('verify', $payment, $requirements);
        $data = Json::decodeObject($response->body);
        $result = $data === null ? null : VerifyResponse::fromArray($data);

        if ($result === null) {
            throw FacilitatorUnavailable::because('verify', sprintf('HTTP %d without a valid VerifyResponse body', $response->status));
        }

        if (! $response->isSuccessful() && $result->isValid) {
            // An error status must never turn into an accepted payment.
            return new VerifyResponse(false, 'unexpected_verify_error', $result->payer);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $requirements  requirements in the wire format of the payload's version
     *
     * @throws FacilitatorUnavailable
     */
    public function settle(PaymentPayload $payment, array $requirements): SettlementResponse
    {
        $response = $this->post('settle', $payment, $requirements);
        $data = Json::decodeObject($response->body);
        $result = $data === null ? null : SettlementResponse::fromArray($data, $payment->network);

        if ($result === null) {
            throw FacilitatorUnavailable::because('settle', sprintf('HTTP %d without a valid SettleResponse body', $response->status));
        }

        if (! $response->isSuccessful() && $result->success) {
            return $result->withFailure('unexpected_settle_error');
        }

        return $result;
    }

    /**
     * @throws FacilitatorUnavailable
     */
    public function supported(): SupportedKinds
    {
        $response = $this->send('supported', new HttpRequest('GET', $this->baseUrl.'/supported', [
            'Accept' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        ]));

        $data = $response->isSuccessful() ? Json::decodeObject($response->body) : null;
        $result = $data === null ? null : SupportedKinds::fromArray($data);

        return $result ?? throw FacilitatorUnavailable::because('supported', sprintf('HTTP %d without a valid SupportedResponse body', $response->status));
    }

    /**
     * The client's payload is embedded verbatim (it was validated as a JSON
     * object when decoding the header), so nothing is lost or reordered.
     *
     * @param  'verify'|'settle'  $operation
     * @param  array<string, mixed>  $requirements
     *
     * @throws FacilitatorUnavailable
     */
    private function post(string $operation, PaymentPayload $payment, array $requirements): HttpResponse
    {
        $body = sprintf(
            '{"x402Version":%d,"paymentPayload":%s,"paymentRequirements":%s}',
            $payment->version->value,
            $payment->json,
            Json::encode($requirements),
        );

        return $this->send($operation, new HttpRequest('POST', $this->baseUrl.'/'.$operation, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        ], $body));
    }

    /**
     * @param  'verify'|'settle'|'supported'  $operation
     *
     * @throws FacilitatorUnavailable
     */
    private function send(string $operation, HttpRequest $request): HttpResponse
    {
        for ($redirects = 0; ; $redirects++) {
            $authenticated = $this->auth === null
                ? $request
                : new HttpRequest($request->method, $request->url, $this->auth->headersFor($operation, $request) + $request->headers, $request->body);

            try {
                $response = $this->transport->send($authenticated);
            } catch (TransportException $exception) {
                throw FacilitatorUnavailable::because($operation, $exception->getMessage(), $exception);
            }

            if (! $response->isMethodPreservingRedirect()) {
                return $response;
            }

            $location = $response->header('location');
            $target = $location === null ? null : self::sameOriginTarget($request->url, $location);

            if ($target === null || $redirects >= self::MAX_REDIRECTS) {
                throw FacilitatorUnavailable::because($operation, sprintf(
                    'refusing redirect to "%s"; configure the final facilitator URL instead',
                    $location ?? '(no Location header)',
                ));
            }

            $request = $request->withUrl($target);
        }
    }

    /**
     * Follow a redirect only within the same host and port (http -> https
     * upgrades allowed), so auth headers never leak to another origin.
     */
    private static function sameOriginTarget(string $from, string $location): ?string
    {
        $origin = parse_url($from);
        if ($origin === false || ! isset($origin['scheme'], $origin['host'])) {
            return null;
        }

        if (str_starts_with($location, '/') && ! str_starts_with($location, '//')) {
            $port = isset($origin['port']) ? ':'.$origin['port'] : '';

            return $origin['scheme'].'://'.$origin['host'].$port.$location;
        }

        $target = parse_url($location);
        if ($target === false || ! isset($target['scheme'], $target['host'])) {
            return null;
        }

        $sameHost = strcasecmp($target['host'], $origin['host']) === 0 && ($target['port'] ?? null) === ($origin['port'] ?? null);
        $allowedScheme = $target['scheme'] === $origin['scheme'] || ($origin['scheme'] === 'http' && $target['scheme'] === 'https');

        return $sameHost && $allowedScheme && ! isset($target['user']) ? $location : null;
    }
}
