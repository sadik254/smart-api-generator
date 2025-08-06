<?php

namespace Saleh\SmartApiGenerator;

use Illuminate\Support\ServiceProvider;
use Saleh\SmartApiGenerator\Console\MakeSmartApi;

class SmartApiServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->commands([
            MakeSmartApi::class,
        ]);
    }

    public function boot()
    {
        //Empty for now, Todo add publishable resources if needed
    }

}
