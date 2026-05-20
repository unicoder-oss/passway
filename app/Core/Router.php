<?php

declare(strict_types=1);

namespace Passway\Core;

/**
 * HTTP router.
 *
 * Registers routes, matches the incoming request, calls the middleware chain
 * and passes control to the controller.
 *
 * Examples:
 *   $router->get('/api/v1/secrets/:id', [SecretsController::class, 'show']);
 *   $router->group('/api/v1', function($r) {
 *       $r->middleware(ApiAuthMiddleware::class)->get('/secrets', ...);
 *   });
 */
final class Router
{
    /** @var array<int, array{method: string, pattern: string, handler: callable|array, middleware: string[]}> */
    private array $routes = [];

    /** @var string[] Middleware for the current group */
    private array $groupMiddleware = [];

    /** @var string Current group prefix */
    private string $groupPrefix = '';

    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    // ------------------------------------------------------------------ //
    //  Route registration                                               //
    // ------------------------------------------------------------------ //

    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    /**
     * Route group with a shared prefix and/or middleware.
     */
    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $previousPrefix     = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix     = $previousPrefix . $prefix;
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix     = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    // ------------------------------------------------------------------ //
    //  Dispatch                                                     //
    // ------------------------------------------------------------------ //

    /**
     * Find a match and execute the handler.
     * Returns Response.
     */
    public function dispatch(Request $request): Response
    {
        $method  = $request->method();
        $path    = $request->path();

        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $params = $this->match($route['pattern'], $path);
            if ($params === null) {
                continue;
            }

            // Route found by path; check the method
            $allowedMethods[] = $route['method'];

            if ($route['method'] !== $method) {
                continue;
            }

            // Set route parameters on the Request
            $request->setRouteParams($params);

            // Run the middleware chain plus handler
            return $this->runMiddleware(
                $request,
                $route['middleware'],
                $route['handler']
            );
        }

        if (!empty($allowedMethods)) {
            // Route exists, but the method is not supported
            return Response::make(405)
                ->withHeader('Allow', implode(', ', array_unique($allowedMethods)))
                ->withContentType('application/json')
                ->withBody(json_encode([
                    'success' => false,
                    'error'   => 'Method Not Allowed',
                ]));
        }

        // Route not found
        if ($request->expectsJson()) {
            return Response::notFound('Route not found');
        }

        return Response::make(404)
            ->withContentType('text/html; charset=utf-8')
            ->withBody('<h1>404 Not Found</h1>');
    }

    // ------------------------------------------------------------------ //
    //  Private methods                                                    //
    // ------------------------------------------------------------------ //

    private function addRoute(string $method, string $path, callable|array $handler, array $middleware): void
    {
        $this->routes[] = [
            'method'     => strtoupper($method),
            'pattern'    => $this->groupPrefix . $path,
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];
    }

    /**
     * Match the route pattern against the actual path.
     * Pattern: /api/v1/secrets/:id/:slug
     * Returns an array of parameters or null if it does not match.
     *
     * @return array<string, string>|null
     */
    private function match(string $pattern, string $path): ?array
    {
        if ($pattern === $path) {
            return [];
        }

        $segments = explode('/', trim($pattern, '/'));
        $regexParts = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^:([a-zA-Z_][a-zA-Z0-9_]*)$/', $segment, $matches) === 1) {
                $regexParts[] = '(?P<' . $matches[1] . '>[^/]+)';
                continue;
            }

            $regexParts[] = preg_quote($segment, '#');
        }

        $regex = '#^/' . implode('/', $regexParts) . '$#u';

        if ($pattern === '/') {
            $regex = '#^/$#u';
        }

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        // Return only named groups (route parameters)
        return array_filter(
            $matches,
            fn($key) => is_string($key),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Run the middleware chain and call the final handler.
     *
     * @param string[] $middlewareClasses
     */
    private function runMiddleware(Request $request, array $middlewareClasses, callable|array $handler): Response
    {
        // Build the chain in reverse order (onion-model)
        $next = function (Request $req) use ($handler): Response {
            return $this->callHandler($handler, $req);
        };

        foreach (array_reverse($middlewareClasses) as $middlewareClass) {
            $mw = $this->container->make($middlewareClass);
            $currentNext = $next;
            $next = function (Request $req) use ($mw, $currentNext): Response {
                return $mw->handle($req, $currentNext);
            };
        }

        return $next($request);
    }

    /**
     * Call the route handler.
     * Supports: callable, [ClassName::class, 'method'], [object, 'method'].
     */
    private function callHandler(callable|array $handler, Request $request): Response
    {
        if (is_callable($handler)) {
            return $handler($request);
        }

        [$class, $method] = $handler;
        $controller = is_string($class) ? $this->container->make($class) : $class;

        if (!method_exists($controller, $method)) {
            throw new \RuntimeException("Method {$method} not found in " . get_class($controller));
        }

        return $controller->$method($request);
    }
}
