<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Facilitator;

/**
 * Answer of POST /settle, also sent to the client base64 encoded in the
 * PAYMENT-RESPONSE header (x402 v2 specification section 5.3).
 */
final readonly class SettlementResponse
{
    /** Non-terminal settle error code, see specification section 9. */
    public const string SETTLEMENT_PENDING = 'settlement_pending';

    /**
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public bool $success,
        public string $transaction,
        public string $network,
        public ?string $payer = null,
        public ?string $errorReason = null,
        public ?string $amount = null,
        public array $extensions = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return self|null null when the body is not a SettleResponse
     */
    public static function fromArray(array $data, string $fallbackNetwork): ?self
    {
        $success = $data['success'] ?? null;
        $transaction = $data['transaction'] ?? null;
        $network = $data['network'] ?? null;

        if (! is_bool($success)) {
            return null;
        }

        if ($success && (! is_string($transaction) || ! is_string($network) || $network === '')) {
            return null;
        }

        return new self(
            $success,
            is_string($transaction) ? $transaction : '',
            is_string($network) && $network !== '' ? $network : $fallbackNetwork,
            self::optionalString($data, 'payer'),
            $success ? null : (self::optionalString($data, 'errorReason') ?? 'unexpected_settle_error'),
            self::optionalString($data, 'amount'),
            is_array($data['extensions'] ?? null) ? $data['extensions'] : [],
        );
    }

    public function withFailure(string $errorReason): self
    {
        return new self(false, $this->transaction, $this->network, $this->payer, $errorReason, $this->amount, $this->extensions);
    }

    /**
     * The facilitator broadcast a transaction but could not confirm it yet.
     */
    public function isPending(): bool
    {
        return ! $this->success && $this->errorReason === self::SETTLEMENT_PENDING && $this->transaction !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['success' => $this->success];

        if ($this->errorReason !== null) {
            $data['errorReason'] = $this->errorReason;
        }

        $data['transaction'] = $this->transaction;
        $data['network'] = $this->network;

        if ($this->payer !== null) {
            $data['payer'] = $this->payer;
        }

        if ($this->amount !== null) {
            $data['amount'] = $this->amount;
        }

        if ($this->extensions !== []) {
            $data['extensions'] = $this->extensions;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
