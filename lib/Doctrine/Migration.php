<?php
/*
 *  $Id: Migration.php 1080 2007-02-10 18:17:08Z jwage $
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

/**
 * Doctrine_Migration
 *
 * this class represents a database view
 *
 * @package     Doctrine
 * @subpackage  Migration
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link        www.doctrine-project.org
 * @since       1.0
 * @version     $Revision: 1080 $
 * @author      Jonathan H. Wage <jwage@mac.com>
 */
class Doctrine_Migration
{
    protected $_migrationTableName = 'migration_version',
              $_migrationTableCreated = false,
              $_connection,
              $_migrationClassesDirectory = array(),
              $_migrationClasses = array(),
              $_reflectionClass,
              $_errors = array(),
              $_process;

    /**
     * @var string Detected style (
     */
    protected $_migrationTableStyle;

    protected static $_migrationClassesForDirectories = array();

    /**
     * Specify the path to the directory with the migration classes.
     * The classes will be loaded and the migration table will be created if it
     * does not already exist
     *
     * @param string $directory The path to your migrations directory
     * @param mixed $connection The connection name or instance to use for this migration
     * @return void
     */
    public function __construct($directory = null, $connection = null)
    {
        $this->_reflectionClass = new ReflectionClass('Doctrine_Migration_Base');

        if (is_null($connection)) {
            $this->_connection = Doctrine_Manager::connection();
        } else {
            if (is_string($connection)) {
                $this->_connection = Doctrine_Manager::getInstance()
                    ->getConnection($connection);
            } else {
                $this->_connection = $connection;
            }
        }

        $this->_process = new Doctrine_Migration_Process($this);

        if ($directory != null) {
            $this->_migrationClassesDirectory = $directory;

            $this->loadMigrationClassesFromDirectory();
        }
    }

    /**
     * @return Doctrine_Connection
     */
    public function getConnection()
    {
        return $this->_connection;
    }

    public function setConnection(Doctrine_Connection $conn)
    {
        $this->_connection = $conn;
    }

    /**
     * Get the migration classes directory
     *
     * @return string $migrationClassesDirectory
     */
    public function getMigrationClassesDirectory()
    {
        return $this->_migrationClassesDirectory;
    }

    /**
     * Get the table name for storing the version number for this migration instance
     *
     * @return string $migrationTableName
     */
    public function getTableName()
    {
        return $this->_migrationTableName;
    }

    /**
     * Set the table name for storing the version number for this migration instance
     *
     * @param string $tableName
     * @return void
     */
    public function setTableName($tableName)
    {
        $this->_migrationTableName = $this->_connection
                ->formatter->getTableName($tableName);
    }

    /**
     * Load migration classes from the passed directory. Any file found with a .php
     * extension will be passed to the loadMigrationClass()
     *
     * @param string $directory  Directory to load migration classes from
     * @return void
     */
    public function loadMigrationClassesFromDirectory($directory = null)
    {
        $directory = $directory ? $directory:$this->_migrationClassesDirectory;

        $classesToLoad = array();
        $classes = get_declared_classes();
        foreach ((array) $directory as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir),
                RecursiveIteratorIterator::LEAVES_ONLY);

            if (isset(self::$_migrationClassesForDirectories[$dir])) {
                foreach (self::$_migrationClassesForDirectories[$dir] as $num => $className) {
                    $this->_migrationClasses[$num] = $className;
                }
            }

            foreach ($it as $file) {
                $info = pathinfo($file->getFileName());
                if (isset($info['extension']) && $info['extension'] == 'php') {
                    require_once($file->getPathName());

                    $array = array_diff(get_declared_classes(), $classes);
                    $className = end($array);

                    $migration_style = null;

                    if ($className) {
                        // check if filename is correct format and throw exception if not
                        if (preg_match('/^[0-9]{10}_/', $file->getFileName()))
                        {
                            if(is_null($migration_style)) {
                                $migration_style = 'number';
                            } elseif($migration_style !== 'number') {
                                throw new Doctrine_Migration_Exception('Illegal mixing of numbered and stepped migration files, detected for ' . $file->getFileName());
                            }
                            $e = explode('_', $file->getFileName());
                        }
                        elseif (preg_match('/^V[0-9]{14}__/', $file->getFileName()))
                        {
                            if(is_null($migration_style)) {
                                $migration_style = 'steps';
                            } elseif($migration_style !== 'steps') {
                                throw new Doctrine_Migration_Exception('Illegal mixing of numbered and stepped migration files, detected for ' . $file->getFileName());
                            }
                            $e = explode('__', $file->getFileName());
                        } else {
                            throw new Doctrine_Migration_Exception(sprintf('Incorrect file format, offending file: %s', $timestamp, $file->getFileName()));
                        }
                        $timestamp = $e[0];

                        if (isset($classesToLoad[$timestamp]))
                        {
                            throw new Doctrine_Migration_Exception(sprintf('Timestamp %s already set, offending file: %s', $timestamp, $file->getFileName()));
                        }

                        $classesToLoad[$timestamp] = array('className' => $className, 'path' => $file->getPathName());
                    }
                }
            }
        }

        if (isset($migration_style)) {
            $this->_migrationTableStyle = $migration_style;
        }

        ksort($classesToLoad, SORT_NUMERIC);
        foreach ($classesToLoad as $class) {
            $this->loadMigrationClass($class['className'], $class['path']);
        }
    }

    /**
     * Load the specified migration class name in to this migration instances queue of
     * migration classes to execute. It must be a child of Doctrine_Migration in order
     * to be loaded.
     *
     * @param string $name
     * @return void
     */
    public function loadMigrationClass($name, $path = null)
    {
        $class = new ReflectionClass($name);

        while ($class->isSubclassOf($this->_reflectionClass)) {

            $class = $class->getParentClass();
            if ($class === false) {
                break;
            }
        }

        if ($class === false) {
            return false;
        }

        if (empty($this->_migrationClasses)) {
            $classMigrationNum = 1;
        } else {
            $nums = array_keys($this->_migrationClasses);
            $num = end($nums);
            $classMigrationNum = $num + 1;
        }

        $this->_migrationClasses[$classMigrationNum] = $name;

        if ($path) {
            $dir = dirname($path);
            self::$_migrationClassesForDirectories[$dir][$classMigrationNum] = $name;
        }
    }

    /**
     * Get all the loaded migration classes. Array where key is the number/version
     * and the value is the class name.
     *
     * @return array $migrationClasses
     */
    public function getMigrationClasses()
    {
        return $this->_migrationClasses;
    }

    /**
     * Set the current version of the database
     *
     * @param integer $number
     * @return void
     */
    public function setCurrentVersion($number)
    {
        if ($this->hasMigrated()) {
            $this->_connection->exec("UPDATE " . $this->_migrationTableName . " SET version = $number");
        } else {
            $this->_connection->exec("INSERT INTO " . $this->_migrationTableName . " (version) VALUES ($number)");
        }
    }

    /**
     * Get the current version of the database
     *
     * @return integer $version
     */
    public function getCurrentVersion()
    {
        $this->_createMigrationTable();

        $result = $this->_connection->fetchColumn("SELECT MAX(version) FROM " . $this->_migrationTableName);

        return isset($result[0]) ? $result[0]:0;
    }

    /**
     * Get the current applied migrations
     *
     * @return string[]
     */
    public function getCurrentSteps()
    {
        $this->_createMigrationTable();

        $result = $this->getConnection()->fetchColumn("SELECT class_name FROM " . $this->_migrationTableName);

        return $result;
    }

    /**
     * Returns true/false for whether or not this database has been migrated in the past
     *
     * @return boolean $migrated
     */
    public function hasMigrated()
    {
        $this->_createMigrationTable();

        $result = $this->_connection->fetchColumn("SELECT version FROM " . $this->_migrationTableName);

        return isset($result[0]) ? true:false;
    }

    /**
     * Gets the latest possible version from the loaded migration classes
     *
     * @return integer $latestVersion
     */
    public function getLatestVersion()
    {
        $versions = array_keys($this->_migrationClasses);
        rsort($versions);

        return isset($versions[0]) ? $versions[0]:0;
    }

    /**
     * Get the next incremented version number based on the latest version number
     * using getLatestVersion()
     *
     * @return integer $nextVersion
     */
    public function getNextVersion()
    {
        return $this->getLatestVersion() + 1;
    }

    /**
     * Get the next incremented class version based on the loaded migration classes
     *
     * @return integer $nextMigrationClassVersion
     */
    public function getNextMigrationClassVersion()
    {
        if (empty($this->_migrationClasses)) {
            return 1;
        } else {
            $nums = array_keys($this->_migrationClasses);
            $num = end($nums) + 1;
            return $num;
        }
    }

    /**
     * Perform a migration process by specifying the migration number/version to
     * migrate to. It will automatically know whether you are migrating up or down
     * based on the current version of the database.
     *
     * @param  integer $to       Version to migrate to
     * @param  boolean $dryRun   Whether or not to run the migrate process as a dry run
     * @return integer $to       Version number migrated to
     * @throws Doctrine_Exception
     */
    public function migrate($to = null, $dryRun = false)
    {
        $this->clearErrors();

        $this->_createMigrationTable();

        $this->_connection->beginTransaction();

        try {

            if ($this->getConnection()->getAttribute(Doctrine_Core::ATTR_MIGRATION_RECORD_STEPS)) {
                $to = $this->_doMigrateSteps($to);
            } else {
                // If nothing specified then lets assume we are migrating from
                // the current version to the latest version
                if ($to === null) {
                    $to = $this->getLatestVersion();
                }
                $this->_doMigrate($to);
            }
        } catch (Exception $e) {
            $this->addError($e);
        }

        if ($this->hasErrors()) {
            $this->_connection->rollback();

            if ($dryRun) {
                return false;
            } else {
                $this->_throwErrorsException();
            }
        } else {
            if ($dryRun) {
                $this->_connection->rollback();
                if ($this->hasErrors()) {
                    return false;
                } else {
                    return $to;
                }
            } else {
                $this->_connection->commit();
                if (!$this->getConnection()->getAttribute(Doctrine_Core::ATTR_MIGRATION_RECORD_STEPS)) {
                    $this->setCurrentVersion($to);
                }
                return $to;
            }
        }
        return false;
    }

    /**
     * Run the migration process but rollback at the very end. Returns true or
     * false for whether or not the migration can be ran
     *
     * @param  string  $to
     * @return boolean $success
     */
    public function migrateDryRun($to = null)
    {
        return $this->migrate($to, true);
    }

    /**
     * Get the number of errors
     *
     * @return integer $numErrors
     */
    public function getNumErrors()
    {
        return count($this->_errors);
    }

    /**
     * Get all the error exceptions
     *
     * @return array $errors
     */
    public function getErrors()
    {
        return $this->_errors;
    }

    /**
     * Clears the error exceptions
     *
     * @return void
     */
    public function clearErrors()
    {
        $this->_errors = array();
    }

    /**
     * Add an error to the stack. Excepts some type of Exception
     *
     * @param Exception $e
     * @return void
     */
    public function addError(Exception $e)
    {
        $this->_errors[] = $e;
    }

    /**
     * Whether or not the migration instance has errors
     *
     * @return boolean
     */
    public function hasErrors()
    {
        return $this->getNumErrors() > 0 ? true:false;
    }

    /**
     * Get instance of migration class for number/version specified
     *
     * @param integer $num
     * @throws Doctrine_Migration_Exception $e
     */
    public function getMigrationClass($num)
    {
        if ($this->getConnection()->getAttribute(Doctrine_Core::ATTR_MIGRATION_RECORD_STEPS)) {
            return new $num;
        } elseif (isset($this->_migrationClasses[$num])) {
            $className = $this->_migrationClasses[$num];
            return new $className();
        }

        throw new Doctrine_Migration_Exception('Could not find migration class for migration step: '.$num);
    }

    public function changeMigrationVersionTableStyle($style, $path)
    {
        if ($this->_migrationTableStyle === $style)
        {
            return false;
        }

        switch($style)
        {
            case 'steps':
                $this->changeMigrationVersionTableStyleToSteps($path);
                break;
            case 'number':
                $this->changeMigrationVersionTableStyleToNumber();
                break;
            default:
                throw new Doctrine_Migration_Exception('Valid styles are "number" and "steps"');
        }
    }

    protected function changeMigrationVersionTableStyleToSteps($path)
    {
        $connection = $this->getConnection();
        $currentVersion = $this->getCurrentVersion();
        if (!$connection->getAttribute(Doctrine_Core::ATTR_MIGRATION_RECORD_STEPS)) {
            // first perform a dry run to check if there's mismatch
            $steps = $this->changeMigrationVersionTableStyleToStepsClasses($path, true);
            $currentVersion = $this->getCurrentVersion();
            if ($currentVersion != count($steps)) {
                throw new Doctrine_Migration_Exception('Current version of database does not match number of migration classes, fix this before continuing');
            }
        }

        $steps = $this->changeMigrationVersionTableStyleToStepsClasses($path);

        if (!$connection->getAttribute(Doctrine_Core::ATTR_MIGRATION_RECORD_STEPS)) {
            $connection->export->dropTable($this->_migrationTableName);
            $connection->setAttribute(Doctrine_Core::ATTR_MIGRATION_RECORD_STEPS, true);
            $this->_migrationTableCreated = false;
            $this->_createMigrationTable();
            $sht = $connection->prepare('insert into ' . $this->_migrationTableName . ' (description, class_name, installed_at) values (?, ?, ?)');
            foreach ($steps as $installed_at => $class) {
                list($null, $description) = explode('__', $class);
                $sht->execute([$description, $class, $installed_at]);
            }
            echo "-----------------------------------\n" . $this->_migrationTableName . " converted to 'steps' style, update your configuration\n";
        }
    }

    private function changeMigrationVersionTableStyleToStepsClasses($path, $dry = false)
    {
        $steps = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path),
            RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($it as $file) {
            /* @var $file SplFileInfo */
            if ($file->getExtension() === 'php') {
                $basename = $file->getBasename('.php');
                if (preg_match('/^([0-9]{10})_([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)$/', $basename, $m)) {
                    $datetime = date('YmdHis', $m[1]);
                    $newname = 'V' . $datetime . '__' . $m[2];
                    if ($dry === false) {
                        echo "$basename => $newname ... ";
                        $newcontent =
                            preg_replace('/(class\s+)[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*/', '$1' . $newname,
                                file_get_contents($file->getRealPath()));
                        $path = $file->getPath();
                        file_put_contents($file->getPath() . '/' . $newname . '.php', $newcontent);
                        unlink($file->getPathname());
                        echo "ok\n";
                    }
                    $steps[date('Y-m-d H:i:s', $m[1])] = $newname;
                }
            }
        }

        return $steps;
    }

    protected function changeMigrationVersionTableStyleToNumber()
    {
        throw new Doctrine_Exception('Not implemented');
    }

    /**
     * Throw an exception with all the errors trigged during the migration
     *
     * @return void
     * @throws Doctrine_Migration_Exception $e
     */
    protected function _throwErrorsException()
    {
        $messages = array();
        $num = 0;
        foreach ($this->getErrors() as $error) {
            $num++;
            $messages[] = ' Error #' . $num . ' - ' .$error->getMessage() . "\n" . $error->getTraceAsString() . "\n";
        }

        $title = $this->getNumErrors() . ' error(s) encountered during migration';
        $message  = $title . "\n";
        $message .= str_repeat('=', strlen($title)) . "\n";
        $message .= implode("\n", $messages);

        throw new Doctrine_Migration_Exception($message);
    }

    /**
     * Do the actual migration process
     *
     * @param  integer $to
     * @return integer $to
     * @throws Doctrine_Exception
     */
    protected function _doMigrate($to)
    {
        $from = $this->getCurrentVersion();

        if ($from == $to) {
            throw new Doctrine_Migration_Exception('Already at version # ' . $to);
        }

        $direction = $from > $to ? 'down':'up';

        if ($direction === 'up') {
            for ($i = $from + 1; $i <= $to; $i++) {
                $this->_doMigrateStep($direction, $i);
            }
        } else {
            for ($i = $from; $i > $to; $i--) {
                $this->_doMigrateStep($direction, $i);
            }
        }

        return $to;
    }

    /**
     * Do the actual migration process, steps version
     *
     * @param  integer $to
     * @return integer $to
     * @throws Doctrine_Exception
     */
    protected function _doMigrateSteps($to)
    {
        $migrations = $this->getMigrationClasses();

        if ($to && !in_array($to, $migrations)) {
            throw new Doctrine_Migration_Exception('Requested migration does not exist: ' . $to);
        }

        $from = $this->getCurrentSteps();
        $runCount = 0;

        if ($to) {
            rsort($migrations);
            $direction = 'down';

            foreach ($migrations as $class) {
                if ($class === $to) {
                    break;
                }
                if (in_array($class, $from)) {
                    $this->_doMigrateStep($direction, $class);
                    $runCount++;
                }
            }
        }

        sort($migrations);
        $direction = 'up';
        foreach ($migrations as $class) {
            if (!in_array($class, $from)) {
                $this->_doMigrateStep($direction, $class);
                $runCount++;
            }
            if ($class === $to) {
                break;
            }
        }

        if ($runCount === 0) {
            throw new Doctrine_Migration_Exception('Already at version # ' . $class);
        }

        return $class;
    }

    /**
     * Perform a single migration step. Executes a single migration class and
     * processes the changes
     *
     * @param string $direction Direction to go, 'up' or 'down'
     * @param integer $class
     * @return void
     */
    protected function _doMigrateStep($direction, $class)
    {
        try {
            $migration = $this->getMigrationClass($class);
            $method = 'pre' . $direction;
            $migration->$method();

            if (method_exists($migration, $direction)) {
                $migration->$direction();
            } else if (method_exists($migration, 'migrate')) {
                $migration->migrate($direction);
            }

            if ($migration->getNumChanges() > 0) {
                $changes = $migration->getChanges();
                if ($direction == 'down' && method_exists($migration, 'migrate')) {
                    $changes = array_reverse($changes);
                }
                foreach ($changes as $value) {
                    list($type, $change) = $value;
                    $funcName = 'process' . Doctrine_Inflector::classify($type);
                    if (method_exists($this->_process, $funcName)) {
                        try {
                            $this->_process->$funcName($change);
                        } catch (Exception $e) {
                            $this->addError($e);
                        }
                    } else {
                        throw new Doctrine_Migration_Exception(sprintf('Invalid migration change type: %s', $type));
                    }
                }
            }

            $method = 'post' . $direction;
            $migration->$method();

            if ($direction === 'up') {
                $this->recordStep($class);
            } else {
                $this->removeStep($class);
            }
        } catch (Exception $e) {
            $this->addError($e);
        }
    }

    protected function recordStep($class)
    {
        $sht = $this->getConnection()->prepare('insert into ' . $this->_migrationTableName
            . ' (description, class_name, installed_at) values (?, ?, now())');
        $description = explode('__', $class);
        $description = str_replace('_', ' ', $description[1]);
        $sht->execute([$description, $class]);
    }

    protected function removeStep($class)
    {
        $sht = $this->getConnection()->prepare('delete from ' . $this->_migrationTableName . ' where class_name = ?');
        $sht->execute([$class]);
    }

    /**
     * Create the migration table and return true. If it already exists it will
     * silence the exception and return false
     *
     * @return boolean $created Whether or not the table was created. Exceptions
     *                          are silenced when table already exists
     */
    protected function _createMigrationTable()
    {
        if ($this->_migrationTableCreated) {
            return true;
        }

        $this->_migrationTableCreated = true;

        try {
            if($this->_connection->getAttribute(Doctrine_Core::ATTR_MIGRATION_RECORD_STEPS)) {
                $this->_connection->export->createTable($this->_migrationTableName,
                    array(
                        'version' =>
                            array(
                                'type' => 'integer',
                                'fixed' => '0',
                                'unsigned' => '',
                                'primary' => '1',
                                'autoincrement' => '1',
                                'length' => '4',
                            ),
                        'description' => array('type' => 'string', 'length' => 255),
                        'class_name' => array('type' => 'string', 'length' => 255),
                        'installed_at' =>
                            array(
                                'notnull' => '1',
                                'type' => 'timestamp',
                                'length' => '25',
                            ),
                    ));
            } else {
                $this->_connection->export->createTable($this->_migrationTableName,
                    array('version' => array('type' => 'integer', 'size' => 11)));
            }

            return true;
        } catch(Exception $e) {
            return false;
        }
    }
}
