<?php

declare(strict_types=1);

namespace Passway\Core;

use Closure;
use ReflectionClass;
use RuntimeException;

/**
 * Простой DI-контейнер (Service Locator + автовайринг).
 *
 * Поддерживает:
 * - bind($abstract, $concrete)   — фабрика (новый экземпляр при каждом вызове)
 * - singleton($abstract, $concrete) — единственный экземпляр
 * - instance($abstract, $object) — зарегистрировать готовый объект
 * - make($abstract)              — разрешить зависимость
 * - Автовайринг через Reflection (конструктор с type-hinted параметрами)
 */
final class Container
{
    private static ?Container $instance = null;

    /** @var array<string, Closure> Фабрики */
    private array $bindings = [];

    /** @var array<string, Closure> Синглтон-фабрики */
    private array $singletons = [];

    /** @var array<string, object> Уже созданные синглтоны */
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
    //  Регистрация                                                         //
    // ------------------------------------------------------------------ //

    /**
     * Зарегистрировать фабрику (новый объект при каждом make()).
     */
    public function bind(string $abstract, Closure|string $concrete): void
    {
        $this->bindings[$abstract] = $this->normalizeFactory($abstract, $concrete);
    }

    /**
     * Зарегистрировать синглтон.
     */
    public function singleton(string $abstract, Closure|string $concrete): void
    {
        $this->singletons[$abstract] = $this->normalizeFactory($abstract, $concrete);
    }

    /**
     * Зарегистрировать уже созданный объект как синглтон.
     */
    public function instance(string $abstract, object $object): void
    {
        $this->instances[$abstract] = $object;
    }

    // ------------------------------------------------------------------ //
    //  Разрешение зависимостей                                             //
    // ------------------------------------------------------------------ //

    /**
     * Создать/получить объект по имени класса или интерфейса.
     *
     * @template T
     * @param class-string<T> $abstract
     * @return T
     */
    public function make(string $abstract): object
    {
        // 1. Готовый синглтон
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract]; // @phpstan-ignore-line
        }

        // 2. Зарегистрированный синглтон
        if (isset($this->singletons[$abstract])) {
            $instance = ($this->singletons[$abstract])($this);
            $this->instances[$abstract] = $instance;
            return $instance; // @phpstan-ignore-line
        }

        // 3. Зарегистрированная фабрика
        if (isset($this->bindings[$abstract])) {
            return ($this->bindings[$abstract])($this); // @phpstan-ignore-line
        }

        // 4. Автовайринг через Reflection
        return $this->autoWire($abstract);
    }

    /**
     * Проверить, зарегистрирована ли зависимость.
     */
    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract])
            || isset($this->singletons[$abstract])
            || isset($this->bindings[$abstract])
            || class_exists($abstract);
    }

    // ------------------------------------------------------------------ //
    //  Приватные методы                                                    //
    // ------------------------------------------------------------------ //

    private function normalizeFactory(string $abstract, Closure|string $concrete): Closure
    {
        if ($concrete instanceof Closure) {
            return $concrete;
        }
        // Если передана строка — имя класса
        return fn(Container $c) => $c->autoWire($concrete);
    }

    /**
     * Автоматически создать объект, разрешив зависимости конструктора.
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
