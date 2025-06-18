<?php
// src/ModelGeneratorServiceProvider.php
namespace ModelGenerator;

use Illuminate\Support\ServiceProvider;
use ModelGenerator\Commands\GenerateModelsCommand;

class ModelGeneratorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->commands([
            GenerateModelsCommand::class,
        ]);
    }
}
