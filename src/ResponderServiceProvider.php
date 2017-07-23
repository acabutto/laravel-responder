<?php

namespace Flugg\Responder;

use Flugg\Responder\Console\MakeTransformer;
use Flugg\Responder\Contracts\ErrorFactory as ErrorFactoryContract;
use Flugg\Responder\Contracts\ErrorMessageResolver as ErrorMessageResolverContract;
use Flugg\Responder\Contracts\ErrorSerializer as ErrorSerializerContract;
use Flugg\Responder\Contracts\Pagination\PaginatorFactory as PaginatorFactoryContract;
use Flugg\Responder\Contracts\Resources\TransformerResolver as ResourceFactoryContract;
use Flugg\Responder\Contracts\Resources\TransformerResolver as TransformerResolverContract;
use Flugg\Responder\Contracts\Responder as ResponderContract;
use Flugg\Responder\Contracts\ResponseFactory as ResponseFactoryContract;
use Flugg\Responder\Contracts\Transformer as TransformerContract;
use Flugg\Responder\Contracts\TransformFactory as TransformFactoryContract;
use Flugg\Responder\Http\Responses\Factories\LaravelResponseFactory;
use Flugg\Responder\Http\Responses\Factories\LumenResponseFactory;
use Flugg\Responder\Pagination\PaginatorFactory;
use Flugg\Responder\Resources\ResourceFactory;
use Flugg\Responder\Transformers\TransformerResolver;
use Illuminate\Foundation\Application as Laravel;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Laravel\Lumen\Application as Lumen;
use League\Fractal\Manager;
use League\Fractal\Serializer\SerializerAbstract;

/**
 * A service provider class responsible for bootstrapping the parts of the Laravel package.
 *
 * @package flugger/laravel-responder
 * @author  Alexander Tømmerås <flugged@gmail.com>
 * @license The MIT License
 */
class ResponderServiceProvider extends BaseServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        if ($this->app instanceof Laravel) {
            $this->registerLaravelBindings();
        } elseif ($this->app instanceof Lumen) {
            $this->registerLumenBindings();
        }

        $this->registerSerializerBindings();
        $this->registerErrorBindings();
        $this->registerFractalBindings();
        $this->registerResourceBindings();
        $this->registerPaginationBindings();
        $this->registerTransformationBindings();
        $this->registerServiceBindings();
    }

    /**
     * Register Laravel bindings.
     *
     * @return void
     */
    protected function registerLaravelBindings()
    {
        $this->app->bind(ResponseFactoryContract::class, function ($app) {
            return $this->decorateResponseFactory($app->make(LaravelResponseFactory::class));
        });
    }

    /**
     * Register Lumen bindings.
     *
     * @return void
     */
    protected function registerLumenBindings()
    {
        $this->app->bind(ResponseFactoryContract::class, function ($app) {
            return $this->decorateResponseFactory($app->make(LumenResponseFactory::class));
        });
    }

    /**
     * Decorate response factories.
     *
     * @param  \Flugg\Responder\Contracts\ResponseFactory $factory
     * @return void
     */
    protected function decorateResponseFactory(ResponseFactoryContract $factory)
    {
        foreach ($this->app->config['responder.decorators'] as $decorator) {
            $factory = new $decorator($factory);
        };

        return $factory;
    }

    /**
     * Register serializer bindings.
     *
     * @return void
     */
    protected function registerSerializerBindings()
    {
        $this->app->bind(ErrorSerializerContract::class, function ($app) {
            return $app->make($app->config['responder.serializers.error']);
        });

        $this->app->bind(SerializerAbstract::class, function ($app) {
            return $app->make($app->config['responder.serializers.success']);
        });
    }

    /**
     * Register error bindings.
     *
     * @return void
     */
    protected function registerErrorBindings()
    {
        $this->app->bind(ErrorMessageResolverContract::class, function () {
            return $app->make(ErrorMessageResolver::class);
        });

        $this->app->bind(ErrorFactoryContract::class, function ($app) {
            return $app->make(ErrorFactory::class);
        });
    }

    /**
     * Register Fractal bindings.
     *
     * @return void
     */
    protected function registerFractalBindings()
    {
        $this->app->bind(Manager::class, function ($app) {
            return $app->make(Manager::class)->setRecursionLimit($app->config['responder.recursion_limit']);
        });
    }

    /**
     * Register pagination bindings.
     *
     * @return void
     */
    protected function registerResourceBindings()
    {
        $this->app->bind(ResourceFactoryContract::class, function ($app) {
            return $app->make(ResourceFactory::class);
        });
    }

    /**
     * Register pagination bindings.
     *
     * @return void
     */
    protected function registerPaginationBindings()
    {
        $this->app->bind(PaginatorFactoryContract::class, function ($app) {
            return new PaginatorFactory($app->make(Request::class)->query());
        });
    }

    /**
     * Register transformation Bindings.
     *
     * @return void
     */
    protected function registerTransformationBindings()
    {
        $this->app->bind(TransformFactoryContract::class, function () {
            return $app->make(FractalTransformFactory::class);
        });

        $this->app->bind(TransformBuilder::class, function ($app) {
            return $app->make(TransformBuilder::class)
                ->serializer($app->make(SerializerAbstract::class))
                ->with($app->make(Request::class)->input($app->config['responder.load_relations_parameter'], []))
                ->only($app->make(Request::class)->input($app->config['responder.filter_fields_parameter'], []));
        });

        $this->app->bind(TransformerResolverContract::class, function ($app) {
            return $app->make(TransformerResolver::class);
        });
    }

    /**
     * Register service bindings.
     *
     * @return void
     */
    protected function registerServiceBindings()
    {
        $this->app->bind(ResponderContract::class, function ($app) {
            return $app->make(Responder::class);
        });

        $this->app->bind(TransformerContract::class, function ($app) {
            return $app->make(Transformer::class);
        });
    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app instanceof Laravel) {
            $this->bootLaravelApplication();
        } elseif ($this->app instanceof Lumen) {
            $this->bootLumenApplication();
        }

        $this->mergeConfigFrom(__DIR__ . '/../config/responder.php', 'responder');
        $this->commands(MakeTransformer::class);
    }

    /**
     * Bootstrap the Laravel application.
     *
     * @return void
     */
    protected function bootLaravelApplication()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../resources/config/responder.php' => config_path('responder.php'),
            ], 'config');

            $this->publishes([
                __DIR__ . '/../resources/lang/en/errors.php' => base_path('resources/lang/en/errors.php'),
            ], 'lang');
        }
    }

    /**
     * Bootstrap the Lumen application.
     *
     * @return void
     */
    protected function bootLumenApplication()
    {
        $this->app->configure('responder');
    }
}