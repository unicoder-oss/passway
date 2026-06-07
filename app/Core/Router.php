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
                    'error'   => __('ui.errors.method_not_allowed'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        // Route not found
        if ($request->expectsJson()) {
            return Response::notFound(__('ui.errors.route_not_found'));
        }

        return Response::make(404)
            ->withContentType('text/html; charset=utf-8')
            ->withBody($this->renderNotFoundHtml());
    }

    // ------------------------------------------------------------------ //
    //  Private methods                                                    //
    // ------------------------------------------------------------------ //

    private function renderNotFoundHtml(): string
    {
        $locale = htmlspecialchars(app_locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars(__('ui.errors.not_found_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $heading = htmlspecialchars(__('ui.errors.not_found_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = htmlspecialchars(__('ui.errors.not_found_message'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $homeLabel = htmlspecialchars(__('ui.invite.go_home'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $styles = $this->renderErrorPageStyles();

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$locale}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$title}</title>
            <style>
                {$styles}
            </style>
        </head>
        <body>
            <div class="card">
                <div class="brand">passway</div>
                <h1>{$heading}</h1>
                <p>{$message}</p>
                <a class="button" href="/">{$homeLabel}</a>
            </div>
        </body>
        </html>
        HTML;
    }

    private function renderErrorPageStyles(): string
    {
        return <<<'CSS'
                :root {
                    color-scheme: light dark;
                    --bg: #f5f5f5;
                    --fg: #161616;
                    --muted: #606060;
                    --panel: #ffffff;
                    --border: #d0d0d0;
                    --button: #4b4b4b;
                    --button-fg: #ffffff;
                    --shadow: 0 12px 32px rgba(0, 0, 0, .05);
                }
                @media (prefers-color-scheme: dark) {
                    :root {
                        --bg: #111111;
                        --fg: #f3f3f3;
                        --muted: #a4a4a4;
                        --panel: #1a1a1a;
                        --border: #393939;
                        --button: #d6d6d6;
                        --button-fg: #111111;
                        --shadow: none;
                    }
                }
                @font-face {
                    font-family: "Passway Mono";
                    src: url("/fonts/NotoSansMono-Regular.woff2") format("woff2");
                    font-weight: 400;
                    font-style: normal;
                    font-display: fallback;
                }
                @font-face {
                    font-family: "Passway Mono";
                    src: url("/fonts/NotoSansMono-Bold.woff2") format("woff2");
                    font-weight: 700;
                    font-style: normal;
                    font-display: fallback;
                }
                * { box-sizing: border-box; }
                body {
                    margin: 0;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 1rem;
                    background: var(--bg);
                    color: var(--fg);
                    font-family: "Passway Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
                    line-height: 1.5;
                }
                a { color: inherit; text-decoration: none; }
                .card {
                    width: 100%;
                    max-width: 520px;
                    padding: 2rem;
                    border: 1px solid var(--border);
                    background: var(--panel);
                    box-shadow: var(--shadow);
                }
                .brand {
                    margin-bottom: 1rem;
                    font-weight: 700;
                    letter-spacing: .02em;
                    text-transform: lowercase;
                }
                h1 { margin: .2rem 0 1rem; font-size: 2rem; }
                p { margin: 0 0 1.25rem; color: var(--muted); }
                .button {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 100%;
                    border: 1px solid var(--button);
                    background: var(--button);
                    color: var(--button-fg);
                    padding: .8rem 1rem;
                    font: inherit;
                    cursor: pointer;
                    transition: opacity .15s ease;
                }
                .button:hover { opacity: .88; }
                .button:focus { outline: 2px solid var(--fg); outline-offset: 2px; }
        CSS;
    }

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
