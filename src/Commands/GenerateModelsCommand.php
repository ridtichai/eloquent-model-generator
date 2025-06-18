<?php
// src/Commands/GenerateModelsCommand.php
namespace ModelGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class GenerateModelsCommand extends Command
{
    protected $signature = 'model:generate {--table=} {--force}';
    protected $description = 'Generate Eloquent models from database schema';

    public function handle()
    {
        $database = config('database.connections.' . config('database.default') . '.database');
        $tables = $this->option('table') ? [$this->option('table')] : DB::select('SHOW TABLES');

        $tableKey = 'Tables_in_' . $database;

        foreach ($tables as $tableObj) {
            $table = $this->option('table') ? $tableObj : $tableObj->$tableKey;
            $columns = DB::select("SHOW COLUMNS FROM $table");

            $modelName = ucfirst(\Str::camel(\Str::singular($table)));
            $path = app_path("Models/$modelName.php");

            if (File::exists($path) && !$this->option('force')) {
                $this->warn("Skipping $modelName (already exists)");
                continue;
            }

            $fillable = collect($columns)
                ->pluck('Field')
                ->reject(fn($col) => in_array($col, ['id', 'created_at', 'updated_at']))
                ->map(fn($col) => "'$col'")
                ->implode(', ');

            $stub = File::get(__DIR__ . '/../../stubs/model.stub');
            $code = str_replace(
                ['{{ class }}', '{{ table }}', '{{ fillable }}'],
                [$modelName, $table, $fillable],
                $stub
            );

            File::ensureDirectoryExists(app_path('Models'));
            File::put($path, $code);
            $this->info("Generated: $modelName");
        }
    }
}
