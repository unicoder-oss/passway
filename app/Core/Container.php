<?php

declare(strict_types=1);

namespace Passway\Core;

use Closure;
use ReflectionClass;
use RuntimeException;

/**
 * Simple DI container (Service Locator + autowiring).
 *
 * Supports:
 * - bind($abstract, $concrete)   - factory (new instance on each call)
 * - singleton($abstract, $concrete) - single instance
 * - instance($abstract, $object) - register an existing object
 * - make($abstract)              - resolve dependency
 * - Autowiring through Reflection (constructor with type-hinted parameters)
 */
final class Container
{
    private static ?Container $instance = null;

    /** @var array<string, Closure> Factories */
    private array $bindings = [];

    /** @var array<string, Closure> Singleton factories */
    private array $singletons = [];

    /** @var array<string, object> Already-created singletons */
    private array $instances = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ------------------------------------------------------------------ //
    //  Registration                                                         //
    // ------------------------------------------------------------------ //

    /**
     * Register a factory (new object on each make()).
     */
    public function bind(string $abstract, Closure|string $concrete): void
    {
        $this->bindings[$abstract] = $this->normalizeFactory($abstract, $concrete);
    }

    /**
     * Register a singleton.
     */
    public function singleton(string $abstract, Closure|string $concrete): void
    {
        $this->singletons[$abstract] = $this->normalizeFactory($abstract, $concrete);
    }

    /**
     * Register an already-created object as a singleton.
     */
    public function instance(string $abstract, object $object): void
    {
        $this->instances[$abstract] = $object;
    }

    // ------------------------------------------------------------------ //
    //  Dependency resolution                                             //
    // ------------------------------------------------------------------ //

    /**
     * Create/get an object by class or interface name.
     *
     * @template T
     * @param class-string<T> $abstract
     * @return T
     */
    public function make(string $abstract): object
    {
        // 1. Existing singleton
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract]; // @phpstan-ignore-line
        }

        // 2. Registered singleton
        if (isset($this->singletons[$abstract])) {
            $instance = ($this->singletons[$abstract])($this);
            $this->instances[$abstract] = $instance;
            return $instance; // @phpstan-ignore-line
        }

        // 3. Registered factory
        if (isset($this->bindings[$abstract])) {
            return ($this->bindings[$abstract])($this); // @phpstan-ignore-line
        }

        // 4. Autowiring through Reflection
        return $this->autoWire($abstract);
    }

    /**
     * Check whether a dependency is registered.
     */
    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract])
            || isset($this->singletons[$abstract])
            || isset($this->bindings[$abstract])
            || class_exists($abstract);
    }

    // ------------------------------------------------------------------ //
    //  Private methods                                                    //
    // ------------------------------------------------------------------ //

    private function normalizeFactory(string $abstract, Closure|string $concrete): Closure
    {
        if ($concrete instanceof Closure) {
            return $concrete;
        }
        // If a string is passed - class name
        return fn(Container $c) => $c->autoWire($concrete);
    }

    /**
     * Automatically create an object by resolving constructor dependencies.
     */
    private function autoWire(string $class): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Cannot resolve [{$class}]: class not found.");
        }

        $reflection  = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if ($type === null) {
                if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                    continue;
                }
                throw new RuntimeException(
                    "Cannot resolve parameter [{$param->getName()}] of [{$class}]: no type hint."
                );
            }

            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;

            if ($this->has($typeName)) {
                $args[] = $this->make($typeName);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            } else {
                throw new RuntimeException(
                    "Cannot auto-wire [{$typeName}] for [{$class}::\${$param->getName()}]."
                );
            }
        }

        return $reflection->newInstanceArgs($args);
    }
}
