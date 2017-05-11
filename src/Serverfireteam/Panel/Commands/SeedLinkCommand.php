<?php namespace Serverfireteam\Panel\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;

class SeedLinkCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'panel:seedlink';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate seed file from table';

    /**
     * Name of the database upon which the seed will be executed.
     *
     * @var string
     */
    protected $databaseName;

    /**
     * New line character for seed files.
     * Double quotes are mandatory!
     *
     * @var string
     */
    private $newLineCharacter = PHP_EOL;

    /**
     * Desired indent for the code.
     * For tabulator use \t
     * Double quotes are mandatory!
     *
     * @var string
     */
    private $indentCharacter = "    ";

    /**
     * @var Composer
     */
    private $composer;

    /**
     * Create a new command instance.
     *
     * @return Serverfireteam\Panel\SeedLinkCommand
     */
    public function __construct(Filesystem $filesystem = null, Composer $composer = null)
    {
        parent::__construct();

        $this->files = $filesystem ?: new Filesystem;
        $this->composer = $composer ?: new Composer($this->files);
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {
        // if clean option is checked empty iSeed template in DatabaseSeeder.php
        if ($this->option('clean')) {
            $this->cleanSection();
        }

        $tables = explode(",", $this->argument('tables'));
        $chunkSize = intval($this->option('max'));
        $exclude = explode(",", $this->option('exclude'));
        $prerunEvents = explode(",", $this->option('prerun'));
        $postrunEvents = explode(",", $this->option('postrun'));
        $dumpAuto = intval($this->option('dumpauto'));
        $indexed = !$this->option('noindex');

        if ($chunkSize < 1) {
            $chunkSize = null;
        }

        $tableIncrement = 0;
        foreach ($tables as $table) {
            $table = trim($table);
            $prerunEvent = null;
            if (isset($prerunEvents[$tableIncrement])) {
                $prerunEvent = trim($prerunEvents[$tableIncrement]);
            }
            $postrunEvent = null;
            if (isset($postrunEvents[$tableIncrement])) {
                $postrunEvent = trim($postrunEvents[$tableIncrement]);
            }
            $tableIncrement++;

            // generate file and class name based on name of the table
            list($fileName, $className) = $this->generateFileName($table);

            // if file does not exist or force option is turned on generate seeder
            if (!\File::exists($fileName) || $this->option('force')) {
                $this->printResult(
                    $this->generateSeed(
                        $table,
                        $this->option('database'),
                        $chunkSize,
                        $exclude,
                        $prerunEvent,
                        $postrunEvent,
                        $dumpAuto,
                        $indexed
                    ),
                    $table
                );
                continue;
            }

            if ($this->confirm('File ' . $className . ' already exist. Do you wish to override it? [yes|no]')) {
                // if user said yes overwrite old seeder
                $this->printResult(
                    $this->generateSeed(
                        $table,
                        $this->option('database'),
                        $chunkSize,
                        $exclude,
                        $prerunEvent,
                        $postrunEvent,
                        $dumpAuto,
                        $indexed
                    ),
                    $table
                );
            }
        }

        return;
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array(
            array('tables', InputArgument::REQUIRED, 'comma separated string of table names'),
        );
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return array(
            array('clean', null, InputOption::VALUE_NONE, 'clean iseed section', null),
            array('force', null, InputOption::VALUE_NONE, 'force overwrite of all existing seed classes', null),
            array('database', null, InputOption::VALUE_OPTIONAL, 'database connection', \Config::get('database.default')),
            array('max', null, InputOption::VALUE_OPTIONAL, 'max number of rows', null),
            array('exclude', null, InputOption::VALUE_OPTIONAL, 'exclude columns', null),
            array('prerun', null, InputOption::VALUE_OPTIONAL, 'prerun event name', null),
            array('postrun', null, InputOption::VALUE_OPTIONAL, 'postrun event name', null),
            array('dumpauto', null, InputOption::VALUE_OPTIONAL, 'run composer dump-autoload', true),
            array('noindex', null, InputOption::VALUE_NONE, 'no indexing in the seed', null),
        );
    }

    /**
     * Provide user feedback, based on success or not.
     *
     * @param  boolean $successful
     * @param  string $table
     * @return void
     */
    protected function printResult($successful, $table)
    {
        if ($successful) {
            $this->info("Created a seed file from table {$table}");
            return;
        }

        $this->error("Could not create seed file from table {$table}");
    }

    /**
     * Generate file name, to be used in test wether seed file already exist
     *
     * @param  string $table
     * @return string
     */
    protected function generateFileName($table)
    {
        if (!\Schema::connection($this->option('database') ? $this->option('database') : config('database.default'))->hasTable($table)) {
            throw new TableNotFoundException("Table $table was not found.");
        }

        // Generate class name and file name
        $className = $this->generateClassName($table);
        $seedPath = base_path() . config('iseed::config.path');
        return [$seedPath . '/' . $className . '.php', $className . '.php'];
    }




    /************************ Seed Methods **********************/



    public function readStubFile($file)
    {
        $buffer = file($file, FILE_IGNORE_NEW_LINES);
        return implode(PHP_EOL, $buffer);
    }

    /**
     * Generates a seed file.
     * @param  string   $table
     * @param  string   $database
     * @param  int      $max
     * @param  string   $prerunEvent
     * @param  string   $postunEvent
     * @return bool
     * @throws Orangehill\Iseed\TableNotFoundException
     */
    public function generateSeed($table, $database = null, $max = 0, $exclude = null, $prerunEvent = null, $postrunEvent = null, $dumpAuto = true, $indexed = true)
    {
        if (!$database) {
            $database = config('database.default');
        }

        $this->databaseName = $database;

        // Check if table exists
        if (!$this->hasTable($table)) {
            throw new TableNotFoundException("Table $table was not found.");
        }

        // Get the data
        $data = $this->getData($table, $max, $exclude);

        // Repack the data
        $dataArray = $this->repackSeedData($data);

        // Generate class name
        $className = $this->generateClassName($table);

        // Get template for a seed file contents
        $stub = $this->readStubFile($this->getStubPath() . '/seed.stub');

        // Get a seed folder path
        $seedPath = $this->getSeedPath();

        // Get a app/database/seeds path
        $seedsPath = $this->getPath($className, $seedPath);

        // Get a populated stub file
        $seedContent = $this->populateStub(
            $className,
            $stub,
            $table,
            $dataArray,
            null,
            $prerunEvent,
            $postrunEvent,
            $indexed
        );

        // Save a populated stub
        $this->files->put($seedsPath, $seedContent);

        // Run composer dump-auto
        if ($dumpAuto) {
            $this->composer->dumpAutoloads();
        }

        // Update the DatabaseSeeder.php file
        return $this->updateDatabaseSeederRunMethod($className) !== false;
    }

    /**
     * Get a seed folder path
     * @return string
     */
    public function getSeedPath()
    {
        return base_path() . config('panel.seed_path');
    }

    /**
     * Get the Data
     * @param  string $table
     * @return Array
     */
    public function getData($table, $max, $exclude = null)
    {
        $result = \DB::connection($this->databaseName)->table($table);

        if (!empty($exclude)) {
            $allColumns = \DB::connection($this->databaseName)->getSchemaBuilder()->getColumnListing($table);
            $result = $result->select(array_diff($allColumns, $exclude));
        }

        if ($max) {
            $result = $result->limit($max);
        }

        return $result->get();
    }

    /**
     * Repacks data read from the database
     * @param  array|object $data
     * @return array
     */
    public function repackSeedData($data)
    {
        if (!is_array($data)) {
            $data = $data->toArray();
        }
        $dataArray = array();
        if (!empty($data)) {
            foreach ($data as $row) {
                $rowArray = array();
                foreach ($row as $columnName => $columnValue) {
                    $rowArray[$columnName] = $columnValue;
                }
                $dataArray[] = $rowArray;
            }
        }
        return $dataArray;
    }

    /**
     * Checks if a database table exists
     * @param string $table
     * @return boolean
     */
    public function hasTable($table)
    {
        return \Schema::connection($this->databaseName)->hasTable($table);
    }

    /**
     * Generates a seed class name (also used as a filename)
     * @param  string  $table
     * @return string
     */
    public function generateClassName($table)
    {
        $tableString = '';
        $tableName = explode('_', $table);
        foreach ($tableName as $tableNameExploded) {
            $tableString .= ucfirst($tableNameExploded);
        }
        return ucfirst($tableString) . 'TableSeeder';
    }

    /**
     * Get the path to the stub file.
     * @return string
     */
    public function getStubPath()
    {
        return base_path().'/vendor/serverfireteam/panel/src/Serverfireteam/Panel/stubs';
    }

    /**
     * Populate the place-holders in the seed stub.
     * @param  string   $class
     * @param  string   $stub
     * @param  string   $table
     * @param  string   $data
     * @param  int      $chunkSize
     * @param  string   $prerunEvent
     * @param  string   $postunEvent
     * @return string
     */
    public function populateStub($class, $stub, $table, $data, $chunkSize = null, $prerunEvent = null, $postrunEvent = null, $indexed = true)
    {
        $chunkSize = $chunkSize ?: 500; //config('iseed::config.chunk_size')
        $inserts = '';
        $chunks = array_chunk($data, $chunkSize);
        foreach ($chunks as $chunk) {
            $this->addNewLines($inserts);
            $this->addIndent($inserts, 2);
            $inserts .= sprintf(
                "\DB::table('%s')->insert(%s);",
                $table,
                $this->prettifyArray($chunk, $indexed)
            );
        }

        $stub = str_replace('{{class}}', $class, $stub);

        $prerunEventInsert = '';
        if ($prerunEvent) {
            $prerunEventInsert .= "\$response = Event::until(new $prerunEvent());";
            $this->addNewLines($prerunEventInsert);
            $this->addIndent($prerunEventInsert, 2);
            $prerunEventInsert .= 'if ($response === false) {';
            $this->addNewLines($prerunEventInsert);
            $this->addIndent($prerunEventInsert, 3);
            $prerunEventInsert .= 'throw new Exception("Prerun event failed, seed wasn\'t executed!");';
            $this->addNewLines($prerunEventInsert);
            $this->addIndent($prerunEventInsert, 2);
            $prerunEventInsert .= '}';
        }

        $stub = str_replace(
            '{{prerun_event}}', $prerunEventInsert, $stub
        );

        if (!is_null($table)) {
            $stub = str_replace('{{table}}', $table, $stub);
        }

        $postrunEventInsert = '';
        if ($postrunEvent) {
            $postrunEventInsert .= "\$response = Event::until(new $postrunEvent());";
            $this->addNewLines($postrunEventInsert);
            $this->addIndent($postrunEventInsert, 2);
            $postrunEventInsert .= 'if ($response === false) {';
            $this->addNewLines($postrunEventInsert);
            $this->addIndent($postrunEventInsert, 3);
            $postrunEventInsert .= 'throw new Exception("Seed was executed but the postrun event failed!");';
            $this->addNewLines($postrunEventInsert);
            $this->addIndent($postrunEventInsert, 2);
            $postrunEventInsert .= '}';
        }

        $stub = str_replace(
            '{{postrun_event}}', $postrunEventInsert, $stub
        );

        $stub = str_replace('{{insert_statements}}', $inserts, $stub);

        return $stub;
    }

    /**
     * Create the full path name to the seed file.
     * @param  string  $name
     * @param  string  $path
     * @return string
     */
    public function getPath($name, $path)
    {
        return $path . '/' . $name . '.php';
    }

    /**
     * Prettify a var_export of an array
     * @param  array  $array
     * @return string
     */
    protected function prettifyArray($array, $indexed = true)
    {
        $content = ($indexed)
            ? var_export($array, true)
            : preg_replace("/[0-9]+ \=\>/i", '', var_export($array, true));

        $lines = explode("\n", $content);

        $inString = false;
        $tabCount = 3;
        for ($i = 1; $i < count($lines); $i++) {
            $lines[$i] = ltrim($lines[$i]);

            //Check for closing bracket
            if (strpos($lines[$i], ')') !== false) {
                $tabCount--;
            }

            //Insert tab count
            if ($inString === false) {
                for ($j = 0; $j < $tabCount; $j++) {
                    $lines[$i] = substr_replace($lines[$i], $this->indentCharacter, 0, 0);
                }
            }

            for ($j = 0; $j < strlen($lines[$i]); $j++) {
                //skip character right after an escape \
                if ($lines[$i][$j] == '\\') {
                    $j++;
                }
                //check string open/end
                else if ($lines[$i][$j] == '\'') {
                    $inString = !$inString;
                }
            }

            //check for openning bracket
            if (strpos($lines[$i], '(') !== false) {
                $tabCount++;
            }
        }

        $content = implode("\n", $lines);

        return $content;
    }

    /**
     * Adds new lines to the passed content variable reference.
     *
     * @param string    $content
     * @param int       $numberOfLines
     */
    private function addNewLines(&$content, $numberOfLines = 1)
    {
        while ($numberOfLines > 0) {
            $content .= $this->newLineCharacter;
            $numberOfLines--;
        }
    }

    /**
     * Adds indentation to the passed content reference.
     *
     * @param string    $content
     * @param int       $numberOfIndents
     */
    private function addIndent(&$content, $numberOfIndents = 1)
    {
        while ($numberOfIndents > 0) {
            $content .= $this->indentCharacter;
            $numberOfIndents--;
        }
    }

    /**
     * Cleans the iSeed section
     * @return bool
     */
    public function cleanSection()
    {
        $databaseSeederPath = base_path() . config('panel.seed_path') . '/DatabaseSeeder.php';

        $content = $this->files->get($databaseSeederPath);

        $content = preg_replace("/(\#iseed_start.+?)\#iseed_end/us", "#iseed_start\n\t\t#iseed_end", $content);

        return $this->files->put($databaseSeederPath, $content) !== false;
        return false;
    }

    /**
     * Updates the DatabaseSeeder file's run method (kudoz to: https://github.com/JeffreyWay/Laravel-4-Generators)
     * @param  string  $className
     * @return bool
     */
    public function updateDatabaseSeederRunMethod($className)
    {
        $databaseSeederPath = base_path() . config('panel.seed_path') . '/DatabaseSeeder.php';

        $content = $this->files->get($databaseSeederPath);
        if (strpos($content, "\$this->call({$className}::class)") === false) {
            if (
                strpos($content, '#iseed_start') &&
                strpos($content, '#iseed_end') &&
                strpos($content, '#iseed_start') < strpos($content, '#iseed_end')
            ) {
                $content = preg_replace("/(\#iseed_start.+?)(\#iseed_end)/us", "$1\$this->call({$className}::class);{$this->newLineCharacter}{$this->indentCharacter}{$this->indentCharacter}$2", $content);
            } else {
                $content = preg_replace("/(run\(\).+?)}/us", "$1{$this->indentCharacter}\$this->call({$className}::class);{$this->newLineCharacter}{$this->indentCharacter}}", $content);
            }
        }

        return $this->files->put($databaseSeederPath, $content) !== false;
    }

}