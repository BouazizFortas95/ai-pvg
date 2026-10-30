<?php

namespace App\Providers;

use App\Contracts\VideoRenderer;
use App\Services\Automation\PipelineRegistry;
use App\Services\Rendering\HyperFramesCompositionBuilder;
use App\Services\Rendering\HyperFramesRenderer;
use App\Services\Rendering\HyperFramesRenderService;
use App\Services\Rendering\RemotionRenderer;
use App\Services\VideoRenderingService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PipelineRegistry::class);
        $this->app->singleton(VideoRenderingService::class);

        $this->app->singleton(RemotionRenderer::class, fn ($app) => new RemotionRenderer($app->make(VideoRenderingService::class)));

        $this->app->singleton(HyperFramesCompositionBuilder::class);
        $this->app->singleton(HyperFramesRenderService::class);
        $this->app->singleton(HyperFramesRenderer::class, fn ($app) => new HyperFramesRenderer(
            $app->make(HyperFramesRenderService::class)
        ));

        $this->app->bind(VideoRenderer::class, function ($app) {
            $driver = config('automation.render.driver', 'remotion');

            return match ($driver) {
                'hyperframes' => $app->make(HyperFramesRenderer::class),
                default => $app->make(RemotionRenderer::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
