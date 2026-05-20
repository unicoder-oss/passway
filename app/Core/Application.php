<?php

declare(strict_types=1);

namespace Passway\Core;

use Throwable;

/**
 * Application core - the assembly point for all components.
 *
 * Initializes:
 * - Configuration (.env)
 * - DI container
 * - Database
 * - Router
 * - Registers routes
 * - Handles the incoming request
 */
final class Application
{
    private static ?Application $instance = null;

    private Config    $config;
    private Container $container;
    private Router    $router;

    private function __construct()
    {
        $this->boot();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ------------------------------------------------------------------ //
    //  Application startup                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Accept the HTTP request, route it, and send the response.
     */
    public function run(): void
    {
        $request = Request::fromGlobals();
        set_request_locale(resolve_request_locale($request));

        try {
            // Global setup check - before routing
            $setupMw  = $this->container->make(\Passway\Middleware\SetupMiddleware::class);
            $response = $setupMw->handle(
                $request,
                fn(Request $req) => $this->router->dispatch($req)
            );
        } catch (Throwable $e) {
            $response = $this->handleException($e, $request);
        }

        $response = $response
            ->withHeader('Content-Language', app_locale())
            ->withHeader('Vary', locale_vary_header($request));

        $response->send();
    }

    // ------------------------------------------------------------------ //
    //  Getters                                                             //
    // ------------------------------------------------------------------ //

    public function getConfig(): Config       { return $this->config; }
    public function getContainer(): Container { return $this->container; }
    public function getRouter(): Router       { return $this->router; }

    // ------------------------------------------------------------------ //
    //  Initialization                                                       //
    // ------------------------------------------------------------------ //

    private function boot(): void
    {
        // 1. Configuration
        $this->config = Config::getInstance();

        // PHP settings depending on the environment
        $this->configurePhp();

        // 2. DI container
        $this->container = Container::getInstance();
        $this->registerCoreBindings();

        // 3. Router
        $this->router = new Router($this->container);

        // 4. Routes
        $this->registerRoutes();

        // 5. Generate the setup token on first startup (if needed)
        $this->maybeInitSetupToken();
    }

    private function configurePhp(): void
    {
        $debug = $this->config->get('APP_DEBUG', false);

        if ($debug) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
            ini_set('display_errors', '0');
        }

        ini_set('log_errors', '1');

        $tz = $this->config->get('APP_TIMEZONE', 'UTC');
        date_default_timezone_set(is_string($tz) ? $tz : 'UTC');

        // Security
        ini_set('expose_php', '0');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set(
            'session.cookie_secure',
            filter_var($_ENV['SESSION_COOKIE_SECURE'] ?? true, FILTER_VALIDATE_BOOLEAN) ? '1' : '0'
        );
        ini_set('session.cookie_samesite', (string) ($_ENV['SESSION_COOKIE_SAMESITE'] ?? 'Strict'));
    }

    private function registerCoreBindings(): void
    {
        $this->container->instance(Config::class, $this->config);
        $this->container->singleton(Database::class, fn() => Database::getInstance());

        // Service layer (Step 2)
        $this->container->singleton(
            \Passway\Services\EncryptionService::class,
            fn() => new \Passway\Services\EncryptionService()
        );
        $this->container->singleton(
            \Passway\Services\HashingService::class,
            fn() => new \Passway\Services\HashingService()
        );
        $this->container->singleton(
            \Passway\Services\TokenService::class,
            fn() => new \Passway\Services\TokenService()
        );
        $this->container->singleton(
            \Passway\Services\TemplateService::class,
            fn() => new \Passway\Services\TemplateService()
        );
        $this->container->singleton(
            \Passway\Services\ViewService::class,
            fn() => new \Passway\Services\ViewService()
        );
        $this->container->singleton(
            \Passway\Services\LoggerService::class,
            fn() => new \Passway\Services\LoggerService()
        );
        $this->container->singleton(
            \Passway\Services\ApiKeyAccessService::class,
            fn() => new \Passway\Services\ApiKeyAccessService()
        );
        $this->container->singleton(
            \Passway\Services\AuditService::class,
            fn($c) => new \Passway\Services\AuditService(
                $c->make(\Passway\Services\LoggerService::class),
            )
        );
        $this->container->singleton(
            \Passway\Services\RotationHttpClient::class,
            fn() => new \Passway\Services\RotationHttpClient()
        );
        $this->container->singleton(
            \Passway\Services\SchedulerService::class,
            fn() => new \Passway\Services\SchedulerService()
        );

        // Service layer (Step 3 - Authentication)
        $this->container->singleton(
            \Passway\Services\SessionService::class,
            fn($c) => new \Passway\Services\SessionService(
                $c->make(\Passway\Services\TokenService::class),
                $c->make(\Passway\Services\HashingService::class),
            )
        );
        $this->container->singleton(
            \Passway\Services\AuthService::class,
            fn($c) => new \Passway\Services\AuthService(
                $c->make(\Passway\Services\HashingService::class),
                $c->make(\Passway\Services\SessionService::class),
                $c->make(\Passway\Services\AuditService::class),
            )
        );
        $this->container->singleton(
            \Passway\Services\TotpService::class,
            fn($c) => new \Passway\Services\TotpService(
                $c->make(\Passway\Services\EncryptionService::class),
            )
        );
        $this->container->singleton(
            \Passway\Services\PasskeyService::class,
            fn() => new \Passway\Services\PasskeyService()
        );
        $this->container->singleton(
            \Passway\Middleware\AuthMiddleware::class,
            fn($c) => new \Passway\Middleware\AuthMiddleware(
                $c->make(\Passway\Services\SessionService::class),
                $c->make(\Passway\Services\ApiKeyService::class),
                $c->make(\Passway\Services\AuditService::class),
            )
        );

        // Service layer (Step 4 - Setup)
        $this->container->singleton(
            \Passway\Services\SetupService::class,
            fn($c) => new \Passway\Services\SetupService(
                $c->make(\Passway\Services\HashingService::class),
                $c->make(\Passway\Services\TokenService::class),
            )
        );
        $this->container->singleton(
            \Passway\Middleware\SetupMiddleware::class,
            fn() => new \Passway\Middleware\SetupMiddleware()
        );
        $this->container->singleton(
            \Passway\Controllers\SetupController::class,
            fn($c) => new \Passway\Controllers\SetupController(
                $c->make(\Passway\Services\SetupService::class),
            )
        );

        // Service layer (Step 10 - API keys + rate limiting)
        $this->container->singleton(
            \Passway\Services\ApiKeyService::class,
            fn($c) => new \Passway\Services\ApiKeyService(
                $c->make(\Passway\Services\OrganizationService::class),
                $c->make(\Passway\Services\AuditService::class),
            )
        );
        $this->container->singleton(
            \Passway\Controllers\ApiKeyController::class,
            fn($c) => new \Passway\Controllers\ApiKeyController(
                $c->make(\Passway\Services\ApiKeyService::class),
            )
        );

        // Service layer (Step 9 - Approval system)
        $this->container->singleton(
            \Passway\Services\ApprovalService::class,
            fn($c) => new \Passway\Services\ApprovalService(
                $c->make(\Passway\Services\OrganizationService::class),
                $c->make(\Passway\Services\EncryptionService::class),
                $c->make(\Passway\Services\AuditService::class),
            )
        );
        $this->container->singleton(
            \Passway\Controllers\ApprovalController::class,
            fn($c) => new \Passway\Controllers\ApprovalController(
                $c->make(\Passway\Services\ApprovalService::class),
            )
        );

        // Service layer (Step 8 - Access control)
        $this->container->singleton(
            \Passway\Services\GroupService::class,
            fn($c) => new \Passway\Services\GroupService(
                $c->make(\Passway\Services\OrganizationService::class),
                $c->make(\Passway\Services\AuditService::class),
            )
        );
        $this->container->singleton(
            \Passway\Services\PermissionService::class,
            fn($c) => new \Passway\Services\PermissionService(
                $c->make(\Passway\Services\OrganizationService::class),
                $c->make(\Passway\Services\GroupService::class),
                $c->make(\Passway\Services\ApiKeyAccessService::class),
                $c->make(\Passway\Services\AuditService::class),
            )
        );
        $this->container->singleton(
            \Passway\Controllers\GroupController::class,
            fn($c) => new \Passway\Controllers\GroupController(
                $c->make(\Passway\Services\GroupService::class),
            )
        );
        $this->container->singleton(
            \Passway\Controllers\PermissionController::class,
            fn($c) => new \Passway\Controllers\PermissionController(
                $c->make(\Passway\Services\PermissionService::class),
            )
        );

        // Service layer (Step 7 - Secrets)
        $this->container->singleton(
            \Passway\Services\SecretService::class,
            fn($c) => new \Passway\Services\SecretService(
                $c->make(\Passway\Services\OrganizationService::class),
                $c->make(\Passway\Services\EncryptionService::class),
                $c->make(\Passway\Services\PermissionService::class),
                $c->make(\Passway\Services\TemplateService::class),
                $c->make(\Passway\Services\AuditService::class),
            )
        );
        $this->container->singleton(
            \Passway\Services\RotationRegistryService::class,
            fn($c) => new \Passway\Services\RotationRegistryService(
                $c->make(\Passway\Services\RotationHttpClient::class),
                $c->make(\Passway\Services\AuditService::class),
            )
        );
        $this->container->singleton(
            \Passway\Services\OrganizationIntegrationService::class,
            fn($c) => new \Passway\Services\OrganizationIntegrationService(
                $c->make(\Passway\Services\OrganizationService::class),
                $c->make(\Passway\Services\EncryptionService::class),
                $c->make(\Passway\Services\AuditService::class),
            )
        );
        $this->container->singleton(
            \Passway\Services\RotationService::class,
            fn($c) => new \Passway\Services\RotationService(
                $c->make(\Passway\Services\SecretService::class),
                $c->make(\Passway\Services\TemplateService::class),
                $c->make(\Passway\Services\SchedulerService::class),
                $c->make(\Passway\Services\RotationHttpClient::class),
                $c->make(\Passway\Services\OrganizationIntegrationService::class),
            )
        );
        $this->container->singleton(
            \Passway\Controllers\AuditController::class,
            fn($c) => new \Passway\Controllers\AuditController(
                new \Passway\Services\AuditService(
                    $c->make(\Passway\Services\LoggerService::class),
                    $c->make(\Passway\Services\OrganizationService::class),
                ),
                $c->make(\Passway\Services\OrganizationService::class),
            )
        );
        $this->container->singleton(
            \Passway\Controllers\WebController::class,
            fn($c) => new \Passway\Controllers\WebController(
                $c->make(\Passway\Services\ViewService::class),
                $c->make(\Passway\Services\OrganizationService::class),
                $c->make(\Passway\Services\DirectoryService::class),
                $c->make(\Passway\Services\SecretService::class),
                $c->make(\Passway\Services\PermissionService::class),
                $c->make(\Passway\Services\RotationService::class),
                $c->make(\Passway\Services\TemplateService::class),
                $c->make(\Passway\Services\InviteService::class),
                new \Passway\Services\AuditService(
                    $c->make(\Passway\Services\LoggerService::class),
                    $c->make(\Passway\Services\OrganizationService::class),
                ),
                $c->make(\Passway\Services\TotpService::class),
                $c->make(\Passway\Services\HashingService::class),
                $c->make(\Passway\Services\ApiKeyService::class),
                $c->make(\Passway\Services\GroupService::class),
                $c->make(\Passway\Services\RotationRegistryService::class),
                $c->make(\Passway\Services\OrganizationIntegrationService::class),
                $c->make(\Passway\Services\ApprovalService::class),
            )
        );
        $this->container->singleton(
            \Passway\Controllers\RotationServiceController::class,
            fn($c) => new \Passway\Controllers\RotationServiceController(
                $c->make(\Passway\Services\RotationRegistryService::class),
            )
        );
        $this->container->singleton(
            \Passway\Controllers\OrganizationIntegrationController::class,
            fn($c) => new \Passway\Controllers\OrganizationIntegrationController(
                $c->make(\Passway\Services\OrganizationIntegrationService::class),
            )
        );
        $this->container->singleton(
            \Passway\Controllers\SecretController::class,
            fn($c) => new \Passway\Controllers\SecretController(
                $c->make(\Passway\Services\SecretService::class),
                $c->make(\Passway\Services\RotationService::class),
            )
        );

        // Service layer (Step 6 - Directories)
        $this->container->singleton(
            \Passway\Services\DirectoryService::class,
            fn($c) => new \Passway\Services\DirectoryService(
                $c->make(\Passway\Services\OrganizationService::class),
                $c->make(\Passway\Services\PermissionService::class),
                $c->make(\Passway\Services\ApiKeyAccessService::class),
            )
        );
        $this->container->singleton(
            \Passway\Controllers\DirectoryController::class,
            fn($c) => new \Passway\Controllers\DirectoryController(
                $c->make(\Passway\Services\DirectoryService::class),
            )
        );

        // Service layer (Step 5 - Organizations, invites, roles)
        $this->container->singleton(
            \Passway\Services\OrganizationService::class,
            fn($c) => new \Passway\Services\OrganizationService(
                $c->make(\Passway\Services\AuditService::class),
            )
        );
        $this->container->singleton(
            \Passway\Services\InviteService::class,
            fn($c) => new \Passway\Services\InviteService(
                $c->make(\Passway\Services\TokenService::class),
                $c->make(\Passway\Services\OrganizationService::class),
                $c->make(\Passway\Services\AuditService::class),
            )
        );
        $this->container->singleton(
            \Passway\Controllers\OrganizationController::class,
            fn($c) => new \Passway\Controllers\OrganizationController(
                $c->make(\Passway\Services\OrganizationService::class),
                $c->make(\Passway\Services\ApiKeyAccessService::class),
            )
        );
        $this->container->singleton(
            \Passway\Controllers\InviteController::class,
            fn($c) => new \Passway\Controllers\InviteController(
                $c->make(\Passway\Services\InviteService::class),
                $c->make(\Passway\Services\OrganizationService::class),
                $c->make(\Passway\Services\HashingService::class),
                $c->make(\Passway\Services\SessionService::class),
                $c->make(\Passway\Services\SetupService::class),
            )
        );
    }

    /**
     * On first startup (setup_complete='0', setup_token_hash='') generates
     * setup token and prints it to stdout so the administrator can find it.
     */
    private function maybeInitSetupToken(): void
    {
        try {
            $db       = Database::getInstance();
            $complete = $db->fetchColumn("SELECT value FROM system_config WHERE key = 'setup_complete'");

            if ($complete !== '0') {
                return; // Уже настроен или значение отсутствует
            }

            /** @var \Passway\Services\SetupService $setupService */
            $setupService = $this->container->make(\Passway\Services\SetupService::class);

            if ($setupService->hasSetupToken()) {
                return; // Токен уже был сгенерирован ранее
            }

            $rawToken = $setupService->generateAndStoreSetupToken();

            if ($rawToken !== null) {
                // Print the token to stdout (visible in Docker logs and when started from CLI)
                file_put_contents('php://stdout', \sprintf(
                    "\n[Passway] *** SETUP REQUIRED ***\nSetup token: %s\nVisit /setup to complete installation.\n\n",
                    $rawToken
                ));
            }
        } catch (Throwable) {
            // DB unavailable at startup - setup will fail later
        }
    }

    private function registerRoutes(): void
    {
        $router = $this->router;

        // Route files
        $routeFiles = [
            PASSWAY_ROOT . '/routes/web.php',
            PASSWAY_ROOT . '/routes/api.php',
        ];

        foreach ($routeFiles as $file) {
            if (file_exists($file)) {
                // Pass $router to the routes file through a variable
                (static function (Router $router) use ($file) {
                    require $file;
                })($router);
            }
        }
    }

    // ------------------------------------------------------------------ //
    //  Exception handling                                                //
    // ------------------------------------------------------------------ //

    private function handleException(Throwable $e, Request $request): Response
    {
        // Log the error
        try {
            if (isset($this->container)) {
                $this->container->make(\Passway\Services\LoggerService::class)->error(
                    'Unhandled application exception',
                    [
                        'class' => get_class($e),
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                );
            } else {
                error_log(sprintf(
                    '[Passway] %s: %s in %s:%d',
                    get_class($e),
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
            }
        } catch (Throwable) {
            error_log(sprintf(
                '[Passway] %s: %s in %s:%d',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $debug = $this->config->get('APP_DEBUG', false);

        if ($request->expectsJson()) {
            $body = ['success' => false, 'error' => 'Internal Server Error'];

            if ($debug) {
                $body['debug'] = [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => explode("\n", $e->getTraceAsString()),
                ];
            }

            return Response::json($body, 500);
        }

        if ($debug) {
            $html = sprintf(
                '<h1>500 Internal Server Error</h1><pre>%s: %s&#10;in %s:%d&#10;&#10;%s</pre>',
                htmlspecialchars(get_class($e)),
                htmlspecialchars($e->getMessage()),
                htmlspecialchars($e->getFile()),
                $e->getLine(),
                htmlspecialchars($e->getTraceAsString())
            );
            return Response::make(500)
                ->withContentType('text/html; charset=utf-8')
                ->withBody($html);
        }

        return Response::make(500)
            ->withContentType('text/html; charset=utf-8')
            ->withBody('<h1>500 Internal Server Error</h1>');
    }
}
