<?php

namespace Saleh\SmartApiGenerator\Console; // This part should be unique for not conflicting with other packages

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class MakeSmartApi extends Command
{
    protected $signature = 'make:smart-api {name}';
    protected $description = 'Generate model, migration, controller, routes for a basic API resource';

    public function handle()
    {
        $name = Str::studly($this->argument('name'));
        $table = Str::snake(Str::pluralStudly($name));
        $fieldsInput = $this->ask("Enter fields (e.g. title:string, body:text, published_at:datetime)");

        // Parse fields
        $fields = array_map(function ($item) {
            $parts = explode(':', $item);
            $name = trim($parts[0]);
            $type = $parts[1] ?? 'string'; // default type string maybe
            $required = isset($parts[2]) && strtolower(trim($parts[2])) === 'req';
            return [
                'name' => $name,
                'type' => $type,
                'required' => $required
            ];
        }, explode(',', $fieldsInput));

        // Prepare strings
        $fillableArray = array_map(fn($f) => "'{$f['name']}'", $fields);
        $fillableString = 'protected $fillable = [' . implode(', ', $fillableArray) . '];';

        $migrationFields = array_map(function ($f) {
            $fieldLine = "\$table->{$f['type']}('{$f['name']}')";
            if (!$f['required']) {
                $fieldLine .= "->nullable()";
            }
            $fieldLine .= ";";
            return $fieldLine;
        }, $fields);
        $migrationFieldsString = implode("\n\t\t\t", $migrationFields);

        // Generate model
        $modelPath = app_path("Models/{$name}.php");
        if (!file_exists(app_path('Models'))) {
            mkdir(app_path('Models'));
        }
        File::put($modelPath, <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class {$name} extends Model
{
    use HasFactory;

    {$fillableString}
}
PHP);

        $this->info(" Model created: {$modelPath}");

        // Generate migration
        $timestamp = now()->format('Y_m_d_His');
        $migrationName = "create_{$table}_table";
        $migrationPath = database_path("migrations/{$timestamp}_{$migrationName}.php");
        File::put($migrationPath, <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('$table', function (Blueprint \$table) {
            \$table->id();
            $migrationFieldsString
            \$table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('$table');
    }
};
PHP);

        $this->info(" Migration created: {$migrationPath}");

        // Generate controller
        $controllerName = "{$name}Controller";
        $controllerPath = app_path("Http/Controllers/{$controllerName}.php");
        $modelVar = Str::camel($name);
        $modelPlural = Str::camel(Str::pluralStudly($name));
        $modelSlug = Str::snake(Str::pluralStudly($name));
        $fillableKeys = implode("', '", array_column($fields, 'name'));
        // Build validation rules string for the controller validation
        $validationRules = array_map(function ($f) {
            $rulePrefix = $f['required'] ? 'required' : 'nullable';
            return match ($f['type']) {
                'string' => "'{$f['name']}' => '{$rulePrefix}|string|max:255'",
                'text' => "'{$f['name']}' => '{$rulePrefix}|string'",
                'integer' => "'{$f['name']}' => '{$rulePrefix}|integer'",
                'boolean' => "'{$f['name']}' => '{$rulePrefix}|boolean'",
                'date', 'datetime', 'timestamp' => "'{$f['name']}' => '{$rulePrefix}|date'",
                'json' => "'{$f['name']}' => '{$rulePrefix}|json'",
                default => "'{$f['name']}' => '{$rulePrefix}'",
            };
        }, $fields);

        $validationRulesString = implode(",\n            ", $validationRules);


        File::put($controllerPath, <<<PHP
<?php

namespace App\Http\Controllers;

use App\Models\\{$name};
use Illuminate\Http\Request;

class {$controllerName} extends Controller
{
    public function index()
    {
        return {$name}::all();
    }

    public function store(Request \$request)
    {
        \$validated = \$request->validate([
            {$validationRulesString}
        ]);
        return {$name}::create(\$request->only(['{$fillableKeys}']));
    }

    public function show({$name} \${$modelVar})
    {
        return \${$modelVar};
    }

    public function update(Request \$request, {$name} \${$modelVar})
    {
        \$validated = \$request->validate([
            {$validationRulesString}
        ]);
        \${$modelVar}->update(\$validated);
        return \${$modelVar};
    }

    public function destroy({$name} \${$modelVar})
    {
        \${$modelVar}->delete();
        return response()->json(['message' => 'success'], 200);
    }
}
PHP);

        $this->info("Controller created: {$controllerPath}");

        // Add route
        // Route file path
        $routesFile = base_path('routes/api.php');

        // Route and import lines
        $routeEntry = "Route::apiResource('{$modelSlug}', {$controllerName}::class);";
        $importController = "use App\\Http\\Controllers\\{$controllerName};";

        // Read existing contents
        $existing = File::get($routesFile);

        // Add import at the top if not exists
        if (!str_contains($existing, $importController)) {
            $existing = preg_replace('/<\?php(\s*)/', "<?php\n\n{$importController}\n", $existing);
        }

        // Add route at the bottom if not exists
        if (!str_contains($existing, $routeEntry)) {
            $existing .= "\n{$routeEntry}\n";
        }

        // Write changes back
        File::put($routesFile, $existing);

        $this->info("Controller import and route added to api.php");
        $this->info("\n All done! Run `php artisan migrate` to apply the migration.");
    }
}
