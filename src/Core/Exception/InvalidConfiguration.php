<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Exception;

use InvalidArgumentException;

/**
 * The developer configured something that can never produce a valid payment
 * (missing pay-to address, unknown network, testnet facilitator on mainnet, ...).
 */
final class InvalidConfiguration extends InvalidArgumentException implements X402Exception {}
