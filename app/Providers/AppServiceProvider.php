<?php

declare(strict_types=1);

namespace App\Providers;

use App\Saasu\DailyBudget;
use App\Saasu\Gateway;
use App\Support\FileInventory;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }

    public function register(): void
    {
        // one inventory per process, however many tools ask; it re-reads the file when it changes
        $this->app->singleton(FileInventory::class);

        // the count lives in the state directory's store, shared by every server process - never
        // the default store, which a test or a misconfiguration could make `array`
        $this->app->singleton(DailyBudget::class, static fn ($app): DailyBudget => new DailyBudget(
            $app->make(CacheFactory::class)->store('saasumcp'),
        ));

        // one per process, so lastSpent() answers for the tool call just made, every request in it
        $this->app->singleton(Gateway::class);
    }
}
