<?php

declare(strict_types=1);

namespace Itsjustmoha\X402\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Itsjustmoha\X402\Core\Asset\AssetRegistry;
use Itsjustmoha\X402\Core\Exception\InvalidConfiguration;
use Itsjustmoha\X402\Core\Facilitator\AuthHeadersProvider;
use Itsjustmoha\X402\Core\Facilitator\FacilitatorClient;
use Itsjustmoha\X402\Core\Gate\PaymentGate;
use Itsjustmoha\X402\Core\Gate\PaymentLock;
use Itsjustmoha\X402\Core\Http\HttpTransport;
use Itsjustmoha\X402\Laravel\Cache\CachePaymentLock;
use Itsjustmoha\X402\Laravel\Http\LaravelHttpTransport;
use Itsjustmoha\X402\Laravel\Http\Middleware\RequirePayment;
use Itsjustmoha\X402\Laravel\Routing\PaidRouteResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class X402ServiceProvider extends ServiceProvider
{
    private const string CONFIG_PATH = __DIR__.'/../../config/x402.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'x402');

        $this->app->singleton(AssetRegistry::class, static function (Application $app): AssetRegistry {
            $assets = self::config($app)->get('x402.assets', []);

            return new AssetRegistry(is_array($assets) ? $assets : []);
        });

        $this->app->singleton(HttpTransport::class, static function (Application $app): HttpTransport {
            $timeout = self::config($app)->get('x402.facilitator.timeout', 30);

            return new LaravelHttpTransport(is_numeric($timeout) ? (float) $timeout : 30.0, 5.0);
        });

        $this->app->singleton(FacilitatorClient::class, static function (Application $app): FacilitatorClient {
            $config = self::config($app);
            $url = $config->get('x402.facilitator.url');
            $auth = $config->get('x402.facilitator.auth');
            $provider = null;

            if (is_string($auth) && $auth !== '') {
                $provider = $app->make($auth);

                if (! $provider instanceof AuthHeadersProvider) {
                    throw new InvalidConfiguration(sprintf('x402.facilitator.auth class "%s" must implement %s.', $auth, AuthHeadersProvider::class));
                }
            }

            return new FacilitatorClient($app->make(HttpTransport::class), is_string($url) ? $url : '', $provider);
        });

        $this->app->singleton(PaymentLock::class, static function (Application $app): PaymentLock {
            $store = self::config($app)->get('x402.lock_store');

            return new CachePaymentLock($app->make('cache'), is_string($store) && $store !== '' ? $store : null);
        });

        $this->app->singleton(PaymentGate::class, static fn (Application $app): PaymentGate => new PaymentGate(
            $app->make(FacilitatorClient::class),
            $app->make(PaymentLock::class),
            (bool) self::config($app)->get('x402.v1_compatibility', false),
        ));

        $this->app->singleton(PaidRouteResolver::class, static fn (Application $app): PaidRouteResolver => new PaidRouteResolver(
            self::config($app),
            $app->make(AssetRegistry::class),
        ));

        $this->app->when(RequirePayment::class)
            ->needs(LoggerInterface::class)
            ->give(static function (Application $app): LoggerInterface {
                $config = self::config($app);

                if (! (bool) $config->get('x402.logging.enabled', true)) {
                    return new NullLogger;
                }

                $channel = $config->get('x402.logging.channel');

                return $app->make('log')->channel(is_string($channel) && $channel !== '' ? $channel : null);
            });
    }

    public function boot(): void
    {
        $this->publishes([self::CONFIG_PATH => config_path('x402.php')], 'x402-config');

        $this->app->make(Router::class)->aliasMiddleware('x402', RequirePayment::class);

        /*
         * Route::get('/report', ReportController::class)->paid('$0.01', ['description' => 'Daily report']);
         *
         * Options: network, pay_to, max_timeout_seconds, description, mime_type, resource, discovery.
         */
        Route::macro('paid', function (string $price, array $options = []): Route {
            /** @var Route $this */
            $this->action[PaidRouteResolver::ROUTE_ACTION_KEY] = ['price' => $price] + $options;

            return $this->middleware('x402');
        });
    }

    private static function config(Application $app): Repository
    {
        return $app->make('config');
    }
}
