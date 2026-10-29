<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Exception;

use RuntimeException;

/**
 * Thrown by an HttpTransport when no HTTP response was received at all
 * (DNS failure, connection refused, TLS error, timeout).
 */
final class TransportException extends RuntimeException implements X402Exception {}
