<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

/**
 * Small service container with constructor autowiring.
 *
 * Deliberately not PSR-11-complete: it does exactly what the application needs
 * (singletons, factories, autowiring of concrete constructor dependencies) and
 * nothing more, so resolution stays predictable and debuggable.
 */
final class Container
{
    /** @var array<string,Closure> */
    private array $bindings = [];

    /** @var array<string,mixed> */
    private array $instances = [];

    /** @var array<string,string> */
    private array $aliases = [];

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public static function setInstance(?self $container): void
    {
        self::$instance = $container;
    }

    public function bind(string $abstract, Closure $factory): void
    {
        $this->bindings[$abstract] = $factory;
        unset($this->instances[$abstract]);
    }

    public function singleton(string $abstract, Closure $factory): void
    {
        $this->bind($abstract, function (Container $c) use ($abstract, $factory) {
            return $this->instances[$abstract] ??= $factory($c);
        });
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->bindings[$abstract]  = static fn () => $instance;
    }

    public function alias(string $alias, string $abstract): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function has(string $abstract): bool
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;

        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $abstract
     * @return T|mixed
     */
    public function make(string $abstract): mixed
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            return ($this->bindings[$abstract])($this);
        }

        return $this->build($abstract);
    }

    private function build(string $class): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Cannot resolve [{$class}]: class does not exist.");
        }

        $reflector = new \ReflectionClass($class);

        if (!$reflector->isInstantiable()) {
            throw new RuntimeException("Cannot resolve [{$class}]: not instantiable. Bind it explicitly.");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->make($type->getName());
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new RuntimeException(
                "Cannot resolve parameter \${$parameter->getName()} of [{$class}]."
            );
        }

        return $reflector->newInstanceArgs($arguments);
    }

    /**
     * Invoke a callable, autowiring any class-typed parameters.
     *
     * @param array<string,mixed> $parameters
     */
    public function call(callable $callable, array $parameters = []): mixed
    {
        $reflection = is_array($callable)
            ? new \ReflectionMethod($callable[0], $callable[1])
            : new \ReflectionFunction(Closure::fromCallable($callable));

        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $parameters)) {
                $arguments[] = $parameters[$name];
                continue;
            }

            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && $this->resolvable($type->getName())) {
                $arguments[] = $this->make($type->getName());
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            $arguments[] = null;
        }

        return $callable(...$arguments);
    }

    private function resolvable(string $class): bool
    {
        return $this->has($class) || class_exists($class);
    }
}
