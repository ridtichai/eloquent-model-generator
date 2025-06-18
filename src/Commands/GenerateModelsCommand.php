<?php

namespace ModelGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class GenerateModelsCommand extends Command
{
    protected $signature = 'model:generate {--table=} {--force}';
    protected $description = 'Generate Eloquent models from database schema';

    public function handle()
    {
        $database = config('database.connections.' . config('database.default') . '.database');
        $tables = $this->option('table') ? [$this->option('table')] : DB::select('SHOW TABLES');
        $tableKey = 'Tables_in_' . $database;

        // Load foreign keys for relation detection
        $foreignKeys = DB::select("
            SELECT
                TABLE_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM
                information_schema.KEY_COLUMN_USAGE
            WHERE
                TABLE_SCHEMA = ? AND
                REFERENCED_TABLE_NAME IS NOT NULL
        ", [$database]);

        $relationshipMap = collect($foreignKeys)->groupBy('TABLE_NAME');

        foreach ($tables as $tableObj) {
            $table = $this->option('table') ? $tableObj : $tableObj->$tableKey;
            $columns = DB::select("SHOW COLUMNS FROM `$table`");

            $modelName = ucfirst(Str::camel(Str::singular($table)));
            $path = app_path("Models/$modelName.php");

            if (File::exists($path) && !$this->option('force')) {
                $this->warn("Skipping $modelName (already exists)");
                continue;
            }

            // Fillable columns
            $fillable = collect($columns)
                ->pluck('Field')
                ->reject(fn($col) => in_array($col, ['id', 'created_at', 'updated_at']))
                ->map(fn($col) => "'$col'")
                ->implode(', ');

            // BelongsTo relationships
            $relations = '';
            if ($relationshipMap->has($table)) {
                foreach ($relationshipMap[$table] as $relation) {
                    $relatedModel = ucfirst(Str::camel(Str::singular($relation->REFERENCED_TABLE_NAME)));
                    $methodName = Str::camel(Str::singular($relation->REFERENCED_TABLE_NAME));

                    $relations .= <<<EOD

    public function {$methodName}()
    {
        return \$this->belongsTo(\\App\\Models\\{$relatedModel}::class, '{$relation->COLUMN_NAME}');
    }

EOD;
                }
            }

            // HasMany relationships
            $hasManyList = collect($foreignKeys)
                ->filter(fn($fk) => $fk->REFERENCED_TABLE_NAME === $table);

            foreach ($hasManyList as $fk) {
                $relatedModel = ucfirst(Str::camel(Str::singular($fk->TABLE_NAME)));
                $methodName = Str::camel(Str::plural($fk->TABLE_NAME));

                $relations .= <<<EOD

    public function {$methodName}()
    {
        return \$this->hasMany(\\App\\Models\\{$relatedModel}::class, '{$fk->COLUMN_NAME}');
    }

EOD;
            }

            // Read and inject into stub
            $stub = File::get(__DIR__ . '/../../stubs/model.stub');
            $code = str_replace(
                ['{{ class }}', '{{ table }}', '{{ fillable }}', '{{ relations }}'],
                [$modelName, $table, $fillable, $relations],
                $stub
            );

            File::ensureDirectoryExists(app_path('Models'));
            File::put($path, $code);
            $this->info("Generated: $modelName");
        }
    }
}
