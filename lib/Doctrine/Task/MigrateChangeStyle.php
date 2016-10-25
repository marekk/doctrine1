<?php

/**
 * @author: marek.kilimajer
 */
class MigrateChangeStyle extends Doctrine_Task
{
    public $description = 'Change how migrations are saved in migration_version table',
        $requiredArguments = array('style' => '"number" (keep only number of migrations run) or "steps" (save migration steps).'),
        $optionalArguments = array(
        'path' => 'Specify alternative path to your migrations directory.',
        'migrations_path' => '',
    );

    public function execute()
    {
        $path = $this->getArgument('path');
        if (empty($path)) {
            $path = $this->getArgument('migrations_path');
        }
        $migration = new Doctrine_Migration($path);

        $result = $migration->changeMigrationVersionTableStyle($this->getArgument('style'));

        if ($result) {
            $this->notify('style successfully changed to ' . $this->getArgument('style'));
        }
    }
}
