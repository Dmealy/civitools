<?php

namespace Urbics\Civitools\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Urbics\Civitools\Console\Migrations\SchemaParser;
use Urbics\Civitools\Console\Migrations\SyntaxBuilder;
use Urbics\Civitools\Traits\PackageNameTrait;
use Urbics\Civitools\Console\Migrations\SqlFunctions;
use Urbics\Civitools\Console\Migrations\SqlTriggers;

/**
 * Based on laracasts/generators
 * DOMDocument should be available by default in php > 5.?
 */
class CiviMakeMigration extends Command
{
    use PackageNameTrait;

    /**
     * The filesystem instance.
     *
     * @var Filesystem
     */
    protected $files;

    /**
     * The SqlFunctions instance.
     *
     * @var SqlFunctions
     */
    protected $sqlFunctions;

    /**
     * The SqlTriggers instance.
     *
     * @var SqlTriggers
     */
    protected $qlTriggers;

    /**
     * The XML schema file name.
     *
     * @var string
     */
    protected $xmlSource;

    /**
     * The path for the migration files.
     *
     * @var string
     */
    protected $path;

    /**
     * The path for the seed files.
     *
     * @var string
     */
    protected $seedPath;

    /**
     * Build Eloquent models?
     *
     * @var boolean
     */
    protected $model;

    /**
     * Schema used to build migrations
     *
     * @var array
     */
    protected $schema;

    /**
     * @var Composer
     */
    private $composer;

    /**
     * Array of tables with seed classes added.
     *
     * @var array
     */
    private $seedTables = [];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'civi:make:migration  
        {--path=civi : Path to stored migration files (under the database/migrations folder)} 
        {--model : Build Eloquent model for each CiviCRM table.}
        {--model-path=Models/Civi : Path to stored models} 
        {--seed : Build a seeder class for each CiviCRM table.}
        {--seeder-class=CiviDefaultSeeder : Seeder class for CiviCRM default data.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate civicrm migration files from xml schema';

    /**
     * Create a new command instance.
     *
     * @param Filesystem $files
     * @param SqlFunctions $sqlFunctions
     * @param SqlTriggers $sqlTriggers
     * 
     * @return void
     */
    public function __construct(Filesystem $files, SqlFunctions $sqlFunctions, SqlTriggers $sqlTriggers)
    {
        parent::__construct();
        $this->files = $files;
        $this->sqlFunctions = $sqlFunctions;
        $this->sqlTriggers = $sqlTriggers;

        $this->composer = app()['composer'];
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->xmlSource = $this->packagePath() . '/schema/xml/Schema.xml';
        $this->path = database_path("migrations/" . $this->option('path'));
        $this->seedPath = database_path("seeds/" . $this->option('path'));
        $this->model = $this->option('model');

        $this->makeSchema();
        $this->process();
    }

    /**
     * Generate the schema from xml source.
     */
    protected function makeSchema()
    {
        $dom = new \DomDocument();
        $xmlString = file_get_contents($this->xmlSource);
        $dom->loadXML($xmlString);
        $dom->documentURI = $this->xmlSource;
        $dom->xinclude();
        $this->schema = (new SchemaParser)->parse(simplexml_import_dom($dom));
        $this->schema = array_merge_recursive($this->schema, $this->sqlFunctions->buildSchema());
        $this->schema = array_merge_recursive($this->schema, $this->sqlTriggers->buildSchema());
    }

    /**
     * Generate the requested files.
     */
    protected function process()
    {
        $this->makeDirectory($this->path);
        $this->makeDirectory($this->seedPath);

        $this->seederSetup();

        // Run the table and index migrations and the model and seeder for each table.
        foreach ($this->schema['create'] as $table) {
            if (!empty($table['drop'])) {
                continue;
            }
            $this->info("Processing " . $table['name']);
            $this->makeMigration($table, 'create');
            $this->makeModel($table);
            $this->makeSeeder($table);
        }

        $this->seederCleanup();

        // Generate functions.
        foreach ($this->schema['create_function'] as $table) {
            $this->info("Processing functions for " . $table['name']);
            $this->makeMigration($table, 'create_function');
        }

        // Generate triggers.
        foreach ($this->schema['create_trigger'] as $table) {
            $this->info("Processing triggers for " . $table['name']);
            $this->makeMigration($table, 'create_trigger');
        }

        // Generate the foreign key migration classes.
        foreach ($this->schema['update'] as $table) {
            if (!empty($table['drop'])) {
                continue;
            }
            $this->info("Processing foreign keys for " . $table['name']);
            $this->makeMigration($table, 'update');
        }

        $this->info("Running composer dumpautoloads");
        $this->composer->dumpAutoloads();

        $this->info('Finished.');
    }

    /**
     * Generates a migration for this table
     *
     * @param  array $table
     * @return null
     */
    protected function makeMigration($table, $action)
    {
        $dirList = $this->files->allFiles(database_path('migrations/' . $this->option('path')));
        foreach ($dirList as $file) {
            if (strpos($file, $action . '_' . $table['name'] . '.php')) {
                $this->comment('Migration already exists.');
                return;
            }
        }
        $this->files->put($this->getFilename($table['name'], $action), $this->compileMigrationStub($table, $action));
        $this->info('Migration created successfully.');
    }

    /**
     * Generate an Eloquent model, if requested by the --model flag.
     * Model is placed in the --model-path folder (Models/Civi by default).
     *
     * @param array $table
     */
    protected function makeModel($table)
    {
        if (!$this->option('model')) {
            return;
        }
        $modelPath = $this->getModelPath($this->getModelName($table));
        if ($this->files->exists($modelPath)) {
            $this->comment('Model already exists.');
            return;
        }
        $this->call('civi:make:model', [
            'name' => ($this->option('model-path') ? $this->option('model-path') . '/': '') . $this->getModelName($table),
        ]);
    }

    protected function seederSetup()
    {
        if ($this->option('seed')) {
            $this->seedTables[] = "\$this->call(" . $this->option('seeder-class') . "::class);";
        }
    }

    /**
     * Generate a Seed class if requested.
     * Seeder is placed in database/seeds/civi by default, or another subdirectory identified in --seed-path
     *
     * @param array $table
     */
    protected function makeSeeder($table)
    {
        if (!$this->option('seed')) {
            return;
        }
        /* Same name casing as ModelName */
        $className = $this->getModelName($table) . 'Seeder';
        $seederPath = $this->getSeederPath($className);
        $this->seedTables[] = "\$this->call($className::class);";
        if ($this->files->exists($seederPath)) {
            $this->comment('Seeder already exists.');
            return;
        }
        $this->call('civi:make:seeder', [
            'name' => $className,
            '--path' => $this->option('path'),
        ]);
    }


    /**
     * Generate additional seeders:
     *  - CiviCRM default values in the class name passed by seeder-class
     *  - CiviDatabaseSeeder: runs civi seeders (except for the default seeder,
     *      these have no content initially and are commented out - uncomment to run them)
     *  - DatabaseSeeder: includes the CiviDatabaseSeeder run command.
     *
     * @return [type]
     */
    public function seederCleanup()
    {
        if (empty($this->seedTables)) {
            return;
        }

        // Generate the CiviCRM default seeder
        $name = $this->getModelName(['name' => $this->option('seeder-class')]);
        $this->info("Processing " . $name);
        if ($this->files->exists(database_path('seeds/' . $this->option('path') . '/' . $name . '.php'))) {
            $this->comment('Seeder already exists.');
        } else {
            $this->call('civi:make:seeder', [
                'name' => $name,
                '--path' => $this->option('path'),
                '--seeder-class' => $this->option('seeder-class'),
            ]);
        }
        // Generate the CiviDatabaseSeeder
        $name = 'CiviDatabaseSeeder';
        $this->info("Processing " . $name);
        if ($this->files->exists(database_path('seeds/' . $name . '.php'))) {
            $this->comment('Seeder already exists.');
        } else {
            $this->call('civi:make:seeder', [
                'name' => $name,
                '--path' => '',
                '--content' => implode("\n        // ", $this->seedTables),
            ]);
        }

        // Laravel installs a DatabaseSeeder file by default
        // - if it does not already have the CiviDatabaseSeeder run commeand, add it here.
        $name = 'DatabaseSeeder';
        $this->info("Processing " . $name);
        $dbSeeder = $this->files->get(database_path('seeds/' . $name . '.php'));
        if ($dbSeeder and (false === strpos($dbSeeder, '$this->call(CiviDatabaseSeeder::class);'))) {
            $dbSeeder = str_replace(
                "run()\n    {\n",
                "run()\n    {\n" . str_repeat(' ', 8) ."\$this->call(CiviDatabaseSeeder::class);\n",
                $dbSeeder
            );
            $this->files->put(database_path('seeds/' . $name . '.php'), $dbSeeder);
            $this->info("Updated seeder.");
            return;
        } elseif (!$dbSeeder) {
            $this->call('civi:make:seeder', [
                'name' => $name,
                '--path' => '',
                '--content' => str_repeat(' ', 8) ."\$this->call(CiviDatabaseSeeder::class);",
            ]);
        } else {
            $this->comment('Seeder already exists.');
        }
    }

    /**
     * Build the directory for the class if necessary.
     *
     * @param  string $path
     * @return string
     */
    protected function makeDirectory($path)
    {
        if (!$this->files->isDirectory($path)) {
            $this->files->makeDirectory($path, 0777, true, true);
        }
    }

    /**
     * Get the destination class path.
     *
     * @param  string $name
     * @return string
     */
    protected function getModelPath($name)
    {
        $name = str_replace($this->getAppNamespace(), '', $name);
        $name = ($this->option('model-path') ? $this->option('model-path') . '/': '') . $name;

        return $this->laravel['path'] . '/' . str_replace('\\', '/', $name) . '.php';
    }

    /**
     * Get the destination class path.
     *
     * @param  string $name
     * @return string
     */
    protected function getSeederPath($name)
    {
        return database_path('seeds/' . $this->option('path') . '/' . str_replace('\\', '/', $name) . '.php');
    }

    /**
     * Get the filename to store the migration.
     *
     * @param  string $name
     * @return string
     */
    protected function getFilename($name, $action)
    {
        return $this->path . '/' . date('Y_m_d_His') . '_' . $action .'_' . $name . '.php';
    }

    /**
     * Get the class name for the Eloquent model generator.
     *
     * @return string
     */
    protected function getModelName($table)
    {
        return ucwords(str_singular(camel_case($table['name'])));
    }

    /**
     * Compile the migration stub.
     *
     * @param array $table
     * @return string
     */
    protected function compileMigrationStub($table, $action)
    {
        $stub = $this->files->get(dirname(__DIR__) . '/Migrations/Stubs/migration.stub');

        $this->replaceClassName($stub, $table, $action)
            ->replaceSchema($stub, $table, $action)
            ->replaceTableName($stub, $table);

        return $stub;
    }

    /**
     * Replace the class name in the stub.
     * Example: create_function, civicrm_contact -> CreateFunctionCivicrmContact
     * 
     * @param  string $stub
     * @param array $table
     * @param string $action
     * @return $this
     */
    protected function replaceClassName(&$stub, $table, $action)
    {
        $className = studly_case($action . '_' . $table['name']);
        $stub = str_replace('{{class}}', $className, $stub);

        return $this;
    }

    /**
     * Replace the schema for the stub.
     *
     * @param  string $stub
     * @return $this
     */
    protected function replaceSchema(&$stub, $table, $action = 'create')
    {
        $schema = (new SyntaxBuilder)->create($table, ['action' => $action]);

        $stub = str_replace(['{{schema_up}}', '{{schema_down}}'], $schema, $stub);

        return $this;
    }

    /**
     * Replace the table name in the stub.
     *
     * @param  string $stub
     * @return $this
     */
    protected function replaceTableName(&$stub, $table)
    {
        $stub = str_replace('{{table}}', $table['name'], $stub);

        return $this;
    }

    /**
     * Get the application namespace.
     *
     * @return string
     */
    protected function getAppNamespace()
    {
        return Container::getInstance()->getNamespace();
    }

}
