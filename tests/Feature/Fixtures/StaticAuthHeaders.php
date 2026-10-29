<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Tests\Feature\Fixtures;

use Itsjustmoha\X402\Core\Facilitator\AuthHeadersProvider;
use Itsjustmoha\X402\Core\Http\HttpRequest;

final class StaticAuthHeaders implements AuthHeadersProvider
{
    public function headersFor(string $operation, HttpRequest $request): array
    {
        return ['Authorization' => 'Bearer test-'.$operation];
    }
}
