<?php

/*
 * Vagner Cardoso <https://github.com/vagnercardosoweb>
 *
 * @author Vagner Cardoso <vagnercardosoweb@gmail.com>
 * @link https://github.com/vagnercardosoweb
 * @license http://www.opensource.org/licenses/mit-license.html MIT License
 * @copyright 21/01/2021 Vagner Cardoso
 */

namespace Core\Facades;

use Slim\App as Application;

/**
 * Class Facade.
 *
 * @author Vagner Cardoso <vagnercardosoweb@gmail.com>
 */
abstract class Facade
{
    /**
     * @var \Slim\App
     */
    protected static Application $app;

    /**
     * @var array
     */
    protected static array $resolvedInstance = [];

    /**
     * @var array
     */
    protected static array $aliases = [
        'App' => App::class,
        'Route' => Route::class,
        'ServerRequest' => ServerRequest::class,
        'Response' => Response::class,
        'Container' => Container::class,
    ];

    /**
     * @param string $method
     * @param array  $arguments
     *
     * @return mixed
     */
    public static function __callStatic(string $method, array $arguments)
    {
        $facadeInstance = static::getFacadeRoot();

        return $facadeInstance->{$method}(...$arguments);
    }

    /**
     * @param array $aliases
     */
    public static function setAliases(array $aliases): void
    {
        self::$aliases = array_merge(self::$aliases, $aliases);
    }

    public static function registerAliases(array $aliases = [])
    {
        self::setAliases($aliases);

        foreach (self::$aliases as $alias => $class) {
            class_alias($class, $alias);
        }
    }

    /**
     * @return mixed|null
     */
    public static function getFacadeRoot()
    {
        return static::resolveFacadeInstance(static::getFacadeAccessor());
    }

    /**
     * @return \Slim\App
     */
    public static function getFacadeApplication(): Application
    {
        return static::$app;
    }

    /**
     * @param \Slim\App $app
     */
    public static function setFacadeApplication(Application $app): void
    {
        static::$app = $app;
    }

    /**
     * @param string $name
     *
     * @return mixed
     */
    protected static function resolveFacadeInstance(string $name)
    {
        if (isset(static::$resolvedInstance[$name])) {
            return static::$resolvedInstance[$name];
        }

        if (!static::$app->getContainer()) {
            return null;
        }

        $container = static::$app->getContainer();

        try {
            return static::$resolvedInstance[$name] = $container->get($name);
        } catch (\Exception $e) {
            throw new \RuntimeException("A facade {$name} root has not been set.");
        }
    }

    /**
     * @return string
     */
    abstract protected static function getFacadeAccessor(): string;
}
