<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Laravel\Routing;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Itsjustmoha\X402\Core\Asset\AssetRegistry;
use Itsjustmoha\X402\Core\Discovery\BazaarExtension;
use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;
use Itsjustmoha\X402\Core\Exception\InvalidPrice;
use Itsjustmoha\X402\Core\Pricing\RequirementsBuilder;
use Itsjustmoha\X402\Core\Protocol\PaymentRequired;
use Itsjustmoha\X402\Core\Protocol\ResourceInfo;

/**
 * Combines config defaults, the options given to Route::paid() and the
 * middleware parameters (x402:<price>,<network>) into the PaymentRequired
 * offer for the current request. Precedence: middleware parameters, then
 * route options, then config.
 */
final readonly class PaidRouteResolver
{
    public const string ROUTE_ACTION_KEY = 'x402';

    public function __construct(
        private Repository $config,
        private AssetRegistry $assets,
    ) {}

    /**
     * @param  list<string>  $parameters  middleware parameters: [price, network]
     *
     * @throws InvalidConfiguration
     * @throws InvalidPrice
     */
    public function resolve(Request $request, array $parameters): PaymentRequired
    {
        $route = $request->route();
        $options = $route instanceof Route ? $route->getAction(self::ROUTE_ACTION_KEY) : null;
        $options = is_array($options) ? $options : [];

        $price = self::nonEmpty($parameters[0] ?? null) ?? self::nonEmpty($options['price'] ?? null)
            ?? throw new InvalidConfiguration('This route has no x402 price. Use ->paid(\'$0.01\') or the "x402:0.01" middleware.');
        $network = self::nonEmpty($parameters[1] ?? null) ?? self::nonEmpty($options['network'] ?? null) ?? $this->string('x402.network');
        $payTo = self::nonEmpty($options['pay_to'] ?? null) ?? self::nonEmpty($this->config->get('x402.pay_to'))
            ?? throw new InvalidConfiguration('Set X402_PAY_TO to the public address of the wallet that should receive payments.');
        $maxTimeout = $options['max_timeout_seconds'] ?? $this->config->get('x402.max_timeout_seconds', 60);

        $this->guardTestnetFacilitator($network);

        $requirements = (new RequirementsBuilder($this->assets))->build($price, $network, $payTo, is_numeric($maxTimeout) ? (int) $maxTimeout : 60);

        $tags = $this->config->get('x402.service.tags', []);
        $resource = ResourceInfo::create(
            self::nonEmpty($options['resource'] ?? null) ?? $request->url(),
            self::nonEmpty($options['description'] ?? null) ?? '',
            self::nonEmpty($options['mime_type'] ?? null) ?? 'application/json',
            self::nonEmpty($this->config->get('x402.service.name')),
            is_array($tags) ? $tags : [],
            self::nonEmpty($this->config->get('x402.service.icon_url')),
        );

        $discovery = $options['discovery'] ?? null;
        $extensions = is_array($discovery) && $route instanceof Route
            ? BazaarExtension::forHttp($request->method(), array_filter($discovery, is_string(...), ARRAY_FILTER_USE_KEY), self::routeTemplate($route), self::pathParams($route))
            : [];

        return new PaymentRequired($resource, [$requirements], $extensions);
    }

    /**
     * The public x402.org facilitator only serves testnets
     * (docs/getting-started/quickstart-for-sellers.mdx, "Running on Mainnet").
     *
     * @throws InvalidConfiguration
     */
    private function guardTestnetFacilitator(string $network): void
    {
        $host = parse_url($this->string('x402.facilitator.url'), PHP_URL_HOST);

        if (! $this->assets->isTestnet($network) && in_array($host, ['x402.org', 'www.x402.org'], true)) {
            throw new InvalidConfiguration(sprintf(
                'Network "%s" is not a known testnet, but the facilitator is the testnet-only x402.org facilitator. Set X402_FACILITATOR_URL to a production facilitator (or mark the network as testnet in x402.assets).',
                $network,
            ));
        }
    }

    /**
     * "users/{user}/posts/{post?}" -> "/users/:user/posts/:post"; null for static routes.
     */
    private static function routeTemplate(Route $route): ?string
    {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! str_contains($uri, '{')) {
            return null;
        }

        return (string) preg_replace('/\{([A-Za-z_][A-Za-z0-9_]*)\??\}/', ':$1', $uri);
    }

    /**
     * @return array<string, string>
     */
    private static function pathParams(Route $route): array
    {
        $params = [];

        foreach ($route->originalParameters() as $name => $value) {
            if (is_string($value)) {
                $params[(string) $name] = $value;
            }
        }

        return $params;
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return self::nonEmpty($value) ?? throw new InvalidConfiguration(sprintf('Config value "%s" must be a non-empty string.', $key));
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
