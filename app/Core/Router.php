<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Route table with named parameters, middleware pipelines and route groups.
 *
 * Patterns use {name} for a segment and {name?} for an optional trailing
 * segment. Compiled to anchored regular expressions once per request.
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,params:array<int,string>,handler:mixed,middleware:array<int,string>,name:?string}> */
    private array $routes = [];

    /** @var array<int,array{prefix:string,middleware:array<int,string>}> */
    private array $groupStack = [];

    /** @var array<string,string> */
    private array $namedRoutes = [];

    /** @var array<int,string> */
    private array $globalMiddleware = [];

    public function __construct(private readonly Container $container)
    {
    }

    /** @param array<int,string> $middleware */
    public function globalMiddleware(array $middleware): void
    {
        $this->globalMiddleware = $middleware;
    }

    /**
     * @param array{prefix?:string,middleware?:array<int,string>} $attributes
     */
    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = [
            'prefix'     => $attributes['prefix'] ?? '',
            'middleware' => $attributes['middleware'] ?? [],
        ];

        $callback($this);

        array_pop($this->groupStack);
    }

    /** @param array<int,string> $middleware */
    public function get(string $pattern, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('GET', $pattern, $handler, $middleware, $name);
    }

    /** @param array<int,string> $middleware */
    public function post(string $pattern, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('POST', $pattern, $handler, $middleware, $name);
    }

    /** @param array<int,string> $middleware */
    public function put(string $pattern, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('PUT', $pattern, $handler, $middleware, $name);
    }

    /** @param array<int,string> $middleware */
    public function patch(string $pattern, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('PATCH', $pattern, $handler, $middleware, $name);
    }

    /** @param array<int,string> $middleware */
    public function delete(string $pattern, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add('DELETE', $pattern, $handler, $middleware, $name);
    }

    /** @param array<int,string> $middleware */
    private function add(string $method, string $pattern, mixed $handler, array $middleware, ?string $name): void
    {
        $prefix          = '';
        $groupMiddleware = [];

        foreach ($this->groupStack as $group) {
            $prefix         .= $group['prefix'];
            $groupMiddleware = array_merge($groupMiddleware, $group['middleware']);
        }

        $full = '/' . trim($prefix . '/' . trim($pattern, '/'), '/');
        $full = $full === '/' ? '/' : rtrim($full, '/');

        [$regex, $params] = $this->compile($full);

        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $full,
            'regex'      => $regex,
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => array_values(array_unique(array_merge($groupMiddleware, $middleware))),
            'name'       => $name,
        ];

        if ($name !== null) {
            $this->namedRoutes[$name] = $full;
        }
    }

    /** @return array{0:string,1:array<int,string>} */
    private function compile(string $pattern): array
    {
        $params = [];

        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(\?)?\}#',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];

                return isset($m[2]) ? '(?:/([^/]+))?' : '([^/]+)';
            },
            $pattern
        ) ?? $pattern;

        // Optional parameters swallow their own leading slash.
        $regex = str_replace('/(?:/([^/]+))?', '(?:/([^/]+))?', $regex);

        return ['#^' . $regex . '$#', $params];
    }

    public function urlFor(string $name, array $params = []): string
    {
        $pattern = $this->namedRoutes[$name] ?? '/';

        foreach ($params as $key => $value) {
            $pattern = str_replace(['{' . $key . '}', '{' . $key . '?}'], rawurlencode((string) $value), $pattern);
        }

        // Drop any unfilled optional segments.
        return preg_replace('#/\{[a-zA-Z_][a-zA-Z0-9_]*\?\}#', '', $pattern) ?? $pattern;
    }

    public function dispatch(Request $request): Response
    {
        $path          = $request->path();
        $method        = $request->method();
        $pathMatched   = false;
        $allowedMethod = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $pathMatched     = true;
            $allowedMethod[] = $route['method'];

            if ($route['method'] !== $method) {
                continue;
            }

            array_shift($matches);
            $params = [];

            foreach ($route['params'] as $index => $paramName) {
                $value = $matches[$index] ?? null;
                if ($value !== null && $value !== '') {
                    $params[$paramName] = rawurldecode($value);
                }
            }

            $request->setRouteParams($params);

            return $this->runPipeline(
                $request,
                array_merge($this->globalMiddleware, $route['middleware']),
                fn (Request $req): Response => $this->runHandler($route['handler'], $req)
            );
        }

        if ($pathMatched) {
            return $this->runPipeline(
                $request,
                $this->globalMiddleware,
                static function () use ($allowedMethod): Response {
                    throw new HttpException(
                        405,
                        'Method not allowed. Allowed: ' . implode(', ', array_unique($allowedMethod)) . '.',
                        'METHOD_NOT_ALLOWED'
                    );
                }
            );
        }

        throw HttpException::notFound();
    }

    /** @param array<int,string> $middleware */
    private function runPipeline(Request $request, array $middleware, callable $destination): Response
    {
        $pipeline = array_reduce(
            array_reverse($middleware),
            function (callable $next, string $definition): callable {
                return function (Request $request) use ($next, $definition): Response {
                    [$class, $argument] = array_pad(explode(':', $definition, 2), 2, null);

                    /** @var Middleware $instance */
                    $instance = $this->container->make((string) $class);

                    return $instance->handle($request, $next, $argument);
                };
            },
            $destination
        );

        return $pipeline($request);
    }

    private function runHandler(mixed $handler, Request $request): Response
    {
        if (is_callable($handler)) {
            $result = $this->container->call($handler, ['request' => $request]);

            return $this->toResponse($result);
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $controller       = $this->container->make($class);
            $result           = $this->container->call([$controller, $method], ['request' => $request]);

            return $this->toResponse($result);
        }

        if (is_array($handler) && count($handler) === 2) {
            $controller = is_string($handler[0]) ? $this->container->make($handler[0]) : $handler[0];
            $result     = $this->container->call([$controller, $handler[1]], ['request' => $request]);

            return $this->toResponse($result);
        }

        throw new \RuntimeException('Invalid route handler.');
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        return Response::html((string) $result);
    }

    /** @return array<int,array<string,mixed>> */
    public function routes(): array
    {
        return $this->routes;
    }
}
