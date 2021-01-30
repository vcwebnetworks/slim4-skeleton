<?php

/*
 * Vagner Cardoso <https://github.com/vagnercardosoweb>
 *
 * @author Vagner Cardoso <vagnercardosoweb@gmail.com>
 * @link https://github.com/vagnercardosoweb
 * @license http://www.opensource.org/licenses/mit-license.html MIT License
 * @copyright 30/01/2021 Vagner Cardoso
 */

declare(strict_types = 1);

namespace Core;

use Core\Facades\Facade;
use Core\Facades\ServerRequest;
use Core\Handlers\HttpErrorHandler;
use Core\Handlers\ShutdownErrorHandler;
use Core\Helpers\Env;
use Core\Helpers\Path;
use DI\Container;
use DI\ContainerBuilder;
use FilesystemIterator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Slim\App as Application;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\Interfaces\RouteParserInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\ResponseEmitter;
use function DI\factory;

/**
 * Class Bootstrap.
 *
 * @author Vagner Cardoso <vagnercardosoweb@gmail.com>
 */
class App
{
    public const VERSION = '1.0.0';

    /**
     * @var \Slim\App
     */
    protected Application $app;

    /**
     * App constructor.
     *
     * @throws \Exception
     */
    public function __construct()
    {
        Env::initialize();

        $this->configurePhpSettings();
        $this->configureSlimApplication();
    }

    /**
     * @return void
     */
    protected function configurePhpSettings(): void
    {
        $locale = Env::get('APP_LOCALE', 'pt_BR');
        $charset = Env::get('APP_CHARSET', 'UTF-8');

        ini_set('default_charset', $charset);
        date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));
        mb_internal_encoding($charset);
        setlocale(LC_ALL, $locale, "{$locale}.{$charset}");

        ini_set('display_errors', Env::get('PHP_DISPLAY_ERRORS', 'Off'));
        ini_set('display_startup_errors', Env::get('PHP_DISPLAY_STARTUP_ERRORS', 'Off'));

        ini_set('log_errors', Env::get('PHP_LOG_ERRORS', 'true'));
        ini_set('error_log', sprintf(Env::get('PHP_ERROR_LOG', Path::storage('/logs/php/%s.log')), date('dmY')));
    }

    /**
     * @throws \Exception
     *
     * @return $this
     */
    protected function configureSlimApplication(): App
    {
        $container = $this->configureContainerBuilder();
        $this->app = $container->get(Application::class);

        Facade::setFacadeApplication($this->app);
        Facade::registerAliases();

        return $this;
    }

    /**
     * @throws \Exception
     *
     * @return \DI\Container
     */
    protected function configureContainerBuilder(): Container
    {
        $providers = [];
        $containerBuilder = new ContainerBuilder();

        if (Env::get('CONTAINER_CACHE', false)) {
            $containerBuilder->enableCompilation(
                Path::storage('/cache/container')
            );
        }

        $containerBuilder->useAutowiring(Env::get('CONTAINER_AUTO_WIRING', false));

        if (file_exists($path = Path::config('/providers.php'))) {
            $providers = require_once "{$path}";
        }

        foreach ($providers as $key => $provider) {
            if (is_string($provider) && class_exists($provider)) {
                $providers[$key] = factory($provider);
            }
        }

        $containerBuilder->addDefinitions(array_merge($providers, [
            Application::class => function (ContainerInterface $container) {
                AppFactory::setContainer($container);

                return AppFactory::create();
            },

            ServerRequestInterface::class => function () {
                $serverRequestCreator = ServerRequestCreatorFactory::create();

                return $serverRequestCreator->createServerRequestFromGlobals();
            },

            ResponseFactoryInterface::class => function (ContainerInterface $container) {
                return $container->get(Application::class)->getResponseFactory();
            },

            RouteParserInterface::class => function (ContainerInterface $container) {
                return $container->get(Application::class)->getRouteCollector()->getRouteParser();
            },

            ResponseInterface::class => function (ContainerInterface $container) {
                return $container->get(ResponseFactoryInterface::class)->createResponse();
            },

            StreamFactoryInterface::class => function () {
                return new StreamFactory();
            },
        ]));

        return $containerBuilder->build();
    }

    /**
     * @return bool
     */
    public static function isCli(): bool
    {
        return in_array(PHP_SAPI, ['cli', 'phpdbg']);
    }

    /**
     * @return bool
     */
    public static function isApi(): bool
    {
        return true === Env::get('APP_ONLY_API', false);
    }

    /**
     * @return bool
     */
    public static function isTesting(): bool
    {
        return 'testing' === Env::get('APP_ENV', 'testing');
    }

    /**
     * @param string|null $path
     *
     * @return $this
     */
    public function registerFolderRoutes(?string $path = null): App
    {
        $path = $path ?? Path::app('/routes');

        /** @var \DirectoryIterator $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $path, FilesystemIterator::SKIP_DOTS
            )
        );

        $iterator->rewind();

        while ($iterator->valid()) {
            if ('php' === $iterator->getExtension()) {
                $this->registerPathRoute($iterator->getRealPath());
            }

            $iterator->next();
        }

        return $this;
    }

    /**
     * @param string $path
     *
     * @return $this
     */
    public function registerPathRoute(string $path): App
    {
        extract(['app' => $this->app]);

        require_once "{$path}";

        return $this;
    }

    /**
     * @return void
     */
    public function run(): void
    {
        $response = $this->app->handle(ServerRequest::getFacadeRoot());
        $responseEmitter = new ResponseEmitter();
        $responseEmitter->emit($response);
    }

    /**
     * @param array|callable|null $middleware
     *
     * @return void
     */
    public function registerMiddleware($middleware = null): void
    {
        if (!$middleware && file_exists($path = Path::config('/middleware.php'))) {
            $middleware = require_once "{$path}";
        }

        if (is_array($middleware)) {
            foreach ($middleware as $class) {
                $this->app->add($class);
            }
        }

        if (is_callable($middleware)) {
            call_user_func($middleware, $this->app);
        }
    }

    /**
     * @return void
     */
    public function registerErrorHandler(): void
    {
        if ('development' === Env::get('APP_ENV', 'development')) {
            error_reporting(E_ALL);
        } else {
            error_reporting(E_ALL ^ E_DEPRECATED);
        }

        // set_error_handler(function ($level, $message, $file = '', $line = 0, $context = []) {
        //     if (error_reporting() & $level) {
        //         throw new \ErrorException($message, 0, $level, $file, $line);
        //     }
        // });

        $serverRequest = ServerRequest::getFacadeRoot();
        $logErrors = Env::get('SLIM_LOG_ERRORS', true);
        $logErrorDetails = Env::get('SLIM_LOG_ERROR_DETAIL', true);
        $displayErrorDetails = Env::get('SLIM_DISPLAY_ERROR_DETAILS', true);

        $httpErrorHandler = new HttpErrorHandler($this->app->getCallableResolver(), $this->app->getResponseFactory());
        $shutdownErrorHandler = new ShutdownErrorHandler($serverRequest, $httpErrorHandler, $displayErrorDetails);
        register_shutdown_function($shutdownErrorHandler);

        $errorMiddleware = $this->app->addErrorMiddleware($displayErrorDetails, $logErrors, $logErrorDetails);
        $errorMiddleware->setDefaultErrorHandler($httpErrorHandler);
    }
}
