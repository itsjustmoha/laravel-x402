<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Core\Exception;

use InvalidArgumentException;

/**
 * A price string could not be converted into atomic token units exactly.
 */
final class InvalidPrice extends InvalidArgumentException implements X402Exception {}
