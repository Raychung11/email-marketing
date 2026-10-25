<?php

declare(strict_types=1);

namespace App\Core;

use App\Database\Connection;
use App\Database\Migrator;
use App\Database\Schema\Schema;
use App\Support\TenantContext;
use Redis;
use RuntimeException;
use Throwable;

/**
 * Composition root. Everything the application needs is wired here, once, and
 * nothing else touches globals.
 */
final class Application
{
    private Container $container;

    private function __construct(
        private readonly string $basePath,
        private readonly string $envFile,
    ) {
        $this->container = new Container();
        Container::setInstance($this->container);
    }

    public static function boot(string $basePath, string $envFile = '.env'): self
    {
        $app = new self($basePath, $envFile);

        Env::load($basePath . '/' . $envFile);

        $app->registerCore();
        $app->configureErrorHandling();
        $app->registerRoutes();

        return $app;
    }

    /**
     * Boot for CLI use (console, workers, scheduler). Routes are not registered
     * because there is no HTTP request to dispatch, which also means a broken
     * route file cannot stop a migration from running.
     */
    public static function bootConsole(string $basePath, string $envFile = '.env'): self
    {
        $app = new self($basePath, $envFile);

        Env::load($basePath . '/' . $envFile);

        $app->registerCore();

        return $app;
    }

    /**
     * Boot for the test suite: in-memory SQLite, array session, array rate
     * limiter, no error handler hijacking PHPUnit's.
     */
    public static function bootForTesting(string $basePath): self
    {
        $app = new self($basePath, '.env.testing');

        Env::load($basePath . '/.env.testing');
        Env::set('APP_ENV', 'testing');
        Env::set('APP_DEBUG', 'true');
        Env::set('APP_KEY', 'base64:' . base64_encode(str_repeat('t', 32)));
        Env::set('DB_DRIVER', 'sqlite');
        Env::set('DB_SQLITE_PATH', ':memory:');
        Env::set('QUEUE_DRIVER', 'sync');
        // The database store, not the array one: the suite should exercise the
        // limiter production actually runs, and an in-memory database gives each
        // test its own counters anyway.
        Env::set('RATE_LIMIT_STORE', 'database');
        Env::set('MAIL_PROVIDER', 'log');
        Env::set('AI_PROVIDER', 'null');
        Env::set('APP_URL', 'http://localhost');
        Env::set('BCRYPT_COST', '4');   // keep the suite fast

        $app->registerCore(sessionDriver: Session::DRIVER_ARRAY);
        $app->registerRoutes();

        return $app;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function basePath(string $append = ''): string
    {
        return $append === '' ? $this->basePath : $this->basePath . '/' . ltrim($append, '/');
    }

    private function registerCore(string $sessionDriver = Session::DRIVER_NATIVE): void
    {
        $c = $this->container;

        $c->instance(self::class, $this);

        // The container resolves itself. Without this, anything that asks for a
        // Container gets a brand new empty one through autowiring — every
        // singleton missing, and confusing "cannot resolve $config of Connection"
        // errors a long way from the cause.
        $c->instance(Container::class, $c);

        $c->singleton(Config::class, function (): Config {
            $config = new Config($this->basePath . '/config');
            $config->load();

            return $config;
        });

        $c->singleton(Logger::class, function (Container $c): Logger {
            $config = $c->make(Config::class);

            return new Logger(
                (string) $config->get('logging.path', $this->basePath . '/storage/logs/app.log'),
                (string) $config->get('logging.level', 'info')
            );
        });

        $c->singleton(Clock::class, static fn (): Clock => new Clock());

        $c->singleton(Connection::class, static function (Container $c): Connection {
            $config     = $c->make(Config::class);
            $driver     = (string) $config->get('database.default', 'mysql');
            $connection = $config->get('database.connections.' . $driver, []);

            return new Connection(is_array($connection) ? $connection : []);
        });

        $c->singleton(Schema::class, static fn (Container $c): Schema => new Schema($c->make(Connection::class)));

        $c->singleton(Migrator::class, static function (Container $c): Migrator {
            return new Migrator(
                $c->make(Connection::class),
                (string) $c->make(Config::class)->get('database.migrations_path')
            );
        });

        $c->singleton(Session::class, static function (Container $c) use ($sessionDriver): Session {
            $config = $c->make(Config::class)->get('session', []);

            return new Session(is_array($config) ? $config : [], $sessionDriver);
        });

        $c->singleton(Csrf::class, static fn (Container $c): Csrf => new Csrf($c->make(Session::class)));

        $c->singleton(Hash::class, static function (Container $c): Hash {
            return new Hash((int) $c->make(Config::class)->get('security.bcrypt_cost', 12));
        });

        $c->singleton(Signer::class, static function (Container $c): Signer {
            $key = (string) $c->make(Config::class)->get('app.key', '');

            if ($key === '') {
                throw new \RuntimeException(
                    'APP_KEY is not set. Run: php cron/console.php key:generate'
                );
            }

            return new Signer($key);
        });

        $c->singleton(Encrypter::class, static function (Container $c): Encrypter {
            return new Encrypter((string) $c->make(Config::class)->get('app.key', ''));
        });

        /*
         * Where rate-limit counters live.
         *
         * Chosen from its own setting, never from the queue driver. Those are
         * unrelated decisions, and inferring one from the other is what left a
         * database-queue deployment counting login attempts in memory — which
         * on shared hosting, where every request is a fresh process, means not
         * counting them at all.
         *
         * In production the in-process store is not a fallback. If neither
         * Redis nor the database can hold a counter, this throws: an
         * unthrottled login page that looks healthy is worse than one that
         * announces it is broken, and the readiness check reports it before a
         * user ever gets there.
         */
        $c->singleton(RateLimitStore::class, static function (Container $c): RateLimitStore {
            $config    = $c->make(Config::class);
            $preferred = (string) $config->get('security.rate_limit.store', 'auto');
            $env       = (string) $config->get('app.env', 'production');

            if ($preferred === 'array') {
                if ($env === 'production') {
                    $c->make(Logger::class)->error(
                        'RATE_LIMIT_STORE=array in production: login and API throttling '
                        . 'will not survive a single request. Set it to redis or database.'
                    );
                }

                return new ArrayRateLimitStore($c->make(Clock::class));
            }

            if ($preferred === 'auto' || $preferred === 'redis') {
                $redis = self::connectRedis($config, 1.5);

                if ($redis !== null) {
                    return new RedisRateLimitStore($redis);
                }

                if ($preferred === 'redis') {
                    throw new RuntimeException(
                        'RATE_LIMIT_STORE=redis but Redis could not be reached. '
                        . 'Start Redis, or set RATE_LIMIT_STORE=database.'
                    );
                }
            }

            $connection = $c->make(Connection::class);

            try {
                $connection->scalar('SELECT COUNT(*) FROM rate_limits WHERE expires_at > ?', ['1970-01-01 00:00:00']);

                return new DatabaseRateLimitStore($connection, $c->make(Clock::class));
            } catch (Throwable $e) {
                if ($env === 'production') {
                    throw new RuntimeException(
                        'No usable rate-limit store: Redis is unreachable and the rate_limits '
                        . 'table is missing. Run `php cron/console.php migrate`. (' . $e->getMessage() . ')',
                        0,
                        $e
                    );
                }

                return new ArrayRateLimitStore($c->make(Clock::class));
            }
        });

        $c->singleton(RateLimiter::class, static fn (Container $c): RateLimiter
            => new RateLimiter($c->make(RateLimitStore::class)));

        $c->singleton(View::class, function (Container $c): View {
            $view   = new View($this->basePath . '/resources/views');
            $config = $c->make(Config::class);

            $view->shareMany([
                'appName'   => $config->get('app.name'),
                'appUrl'    => $config->get('app.url'),
                'appEnv'    => $config->get('app.env'),
                'company'   => (array) $config->get('app.company', []),
                'csrfToken' => '',   // replaced per-request by ViewContextMiddleware
            ]);

            return $view;
        });

        $c->singleton(TenantContext::class, static fn (): TenantContext => new TenantContext());

        $c->singleton(
            \App\Support\HttpClient::class,
            static fn (): \App\Support\HttpClient => new \App\Support\CurlHttpClient()
        );

        $c->singleton(
            \App\Support\Heartbeat::class,
            static fn (): \App\Support\Heartbeat => new \App\Support\Heartbeat(base_path('storage/framework'))
        );

        /*
         * These three carry per-request state — the resolved identity, and the
         * actor stamped onto log entries. They MUST be singletons: two instances
         * would disagree about who is acting, and a permission check against a
         * stale copy is a security bug, not a performance one.
         */
        $c->singleton(\App\Services\AuthManager::class, static fn (Container $c): \App\Services\AuthManager
            => new \App\Services\AuthManager(
                $c->make(Session::class),
                $c->make(\App\Repositories\UserRepository::class),
                $c->make(\App\Repositories\MembershipRepository::class),
                $c->make(\App\Repositories\RoleRepository::class),
                $c->make(TenantContext::class),
            ));

        $c->singleton(\App\Services\AuditService::class, static fn (Container $c): \App\Services\AuditService
            => new \App\Services\AuditService(
                $c->make(\App\Repositories\AuditLogRepository::class),
                $c->make(TenantContext::class),
                $c->make(Clock::class),
                $c->make(Logger::class),
            ));

        $c->singleton(\App\Services\ActivityService::class, static fn (Container $c): \App\Services\ActivityService
            => new \App\Services\ActivityService(
                $c->make(\App\Repositories\ActivityLogRepository::class),
                $c->make(Clock::class),
            ));

        $c->singleton(Router::class, static fn (Container $c): Router => new Router($c));

        $this->registerProviders();
    }

    /**
     * Provider bindings. Each of these is chosen by configuration, so swapping an
     * email or AI vendor is an .env change and nothing else.
     */
    private function registerProviders(): void
    {
        $c = $this->container;

        // --- Email ----------------------------------------------------------
        $c->singleton(\App\Mail\EmailProviderInterface::class, static function (Container $c): \App\Mail\EmailProviderInterface {
            $config   = $c->make(Config::class);
            $selected = (string) $config->get('mail.provider', 'ses');
            $logger   = $c->make(Logger::class);

            return match ($selected) {
                'log'   => new \App\Mail\LogEmailProvider(
                    (string) $config->get('mail.providers.log.path', base_path('storage/logs/mail.log')),
                    $logger
                ),
                default => new \App\Mail\AmazonSesProvider(
                    (array) $config->get('mail.providers.ses', []),
                    $logger,
                    $c->make(\App\Support\HttpClient::class)
                ),
            };
        });

        $c->alias(\App\Mail\ChannelProviderInterface::class, \App\Mail\EmailProviderInterface::class);

        // Text messaging, behind the same channel abstraction as email so the
        // automation engine never names a vendor or a channel.
        $c->singleton(\App\Messaging\TextProviderInterface::class, static function (Container $c): \App\Messaging\TextProviderInterface {
            /** @var Config $config */
            $config = $c->make(Config::class);
            $driver = (string) $config->get('messaging.provider', 'log');

            if ($driver === 'twilio') {
                return new \App\Messaging\TwilioTextProvider(
                    (array) $config->get('messaging.providers.twilio', []),
                    'sms',
                    $c->make(Logger::class)
                );
            }

            return new \App\Messaging\LogTextProvider(
                (string) $config->get('messaging.providers.log.path', '/tmp/sms.log'),
                'sms',
                $c->make(Logger::class)
            );
        });

        // --- DNS (domain verification) --------------------------------------
        $c->singleton(\App\Support\DnsResolver::class, static fn (): \App\Support\DnsResolver
            => new \App\Support\SystemDnsResolver());

        // Used to fetch the SNS signing certificate. An interface so a test can
        // verify a real signature without reaching the internet.
        $c->singleton(\App\Support\HttpFetcher::class, static fn (Container $c): \App\Support\HttpFetcher
            => new \App\Support\CurlHttpFetcher($c->make(Logger::class)));

        // --- AI -------------------------------------------------------------
        $c->singleton(\App\AI\AiProviderInterface::class, static function (Container $c): \App\AI\AiProviderInterface {
            $config   = $c->make(Config::class);
            $selected = (string) $config->get('ai.provider', 'openai');

            if ($selected === 'openai') {
                $provider = new \App\AI\OpenAiProvider(
                    (array) $config->get('ai.providers.openai', []),
                    $c->make(Logger::class)
                );

                // An unconfigured provider must refuse rather than pretend, so fall
                // back to the null provider which says so plainly.
                return $provider->isConfigured() ? $provider : new \App\AI\NullAiProvider();
            }

            return new \App\AI\NullAiProvider();
        });

        // --- Queue ----------------------------------------------------------
        $c->singleton(\App\Queue\QueueDriver::class, static function (Container $c): \App\Queue\QueueDriver {
            $config = $c->make(Config::class);
            $driver = (string) $config->get('queue.driver', 'redis');

            if ($driver === 'sync') {
                return new \App\Queue\SyncQueueDriver();
            }

            if ($driver === 'redis') {
                $redis = self::connectRedis($config, 2.0);

                if ($redis !== null) {
                    /** @var array<string,mixed> $redisConfig */
                    $redisConfig = $config->get('queue.redis', []);

                    return new \App\Queue\RedisQueueDriver(
                        $redis,
                        $c->make(Connection::class),
                        $c->make(Clock::class),
                        (string) ($redisConfig['prefix'] ?? 'aigh:queue:')
                    );
                }

                $c->make(Logger::class)->error(
                    'Redis is unavailable; falling back to the database queue driver.'
                );
            }

            return new \App\Queue\DatabaseQueueDriver($c->make(Connection::class), $c->make(Clock::class));
        });
    }

    private function registerRoutes(): void
    {
        /** @var Router $router */
        $router = $this->container->make(Router::class);

        $router->globalMiddleware([
            \App\Middleware\SecurityHeaders::class,
            \App\Middleware\StartSession::class,
            \App\Middleware\ShareViewContext::class,
            \App\Middleware\VerifyCsrfToken::class,
        ]);

        $registrar = require $this->basePath . '/routes/web.php';
        $registrar($router);

        $apiRegistrar = require $this->basePath . '/routes/api.php';
        $apiRegistrar($router);
    }

    private function configureErrorHandling(): void
    {
        $debug = (bool) $this->container->make(Config::class)->get('app.debug', false);

        // Never render a stack trace to an end user.
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public function handle(Request $request): Response
    {
        /** @var Router $router */
        $router = $this->container->make(Router::class);

        try {
            $response = $router->dispatch($request);
        } catch (Throwable $e) {
            $response = $this->container->make(\App\Core\ExceptionRenderer::class)->render($request, $e);
        }

        return $this->applyGlobalHeaders($response);
    }

    private function applyGlobalHeaders(Response $response): Response
    {
        $config = $this->container->make(Config::class);

        /** @var array<string,string> $headers */
        $headers = $config->get('security.headers', []);

        foreach ($headers as $name => $value) {
            $response->withHeader($name, $value);
        }

        if ((bool) $config->get('app.force_https', false)) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    public function run(): void
    {
        $request = Request::capture();

        $this->handle($request)->send();
    }

    /**
     * A connected Redis handle, or null.
     *
     * One place, so the queue driver and the rate limiter cannot end up
     * disagreeing about whether Redis is available or how to reach it.
     */
    private static function connectRedis(Config $config, float $timeout): ?Redis
    {
        if (!extension_loaded('redis')) {
            return null;
        }

        /** @var array<string,mixed> $settings */
        $settings = $config->get('queue.redis', []);

        try {
            $redis = new Redis();
            $redis->connect(
                (string) ($settings['host'] ?? '127.0.0.1'),
                (int) ($settings['port'] ?? 6379),
                $timeout
            );

            if ((string) ($settings['password'] ?? '') !== '') {
                $redis->auth((string) $settings['password']);
            }

            $redis->select((int) ($settings['database'] ?? 0));

            return $redis;
        } catch (Throwable) {
            return null;
        }
    }
}
