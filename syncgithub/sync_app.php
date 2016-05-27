<?php

class SyncApp
{
    const LOG_ERROR = 1;
    const LOG_WARNING = 2;
    const LOG_INFO = 4;
    const LOG_DEBUG = 8;

    /**
     * Contains the configuration array, this is only instantiated on the construction of the SyncApp.
     * @var array
     */
    public static $config;

    /**
     * Log messages to the console, levels are defined in this class as constants with LOG_{level}.
     *
     * @param $level
     * @param $message
     */
    public static function log($level, $message)
    {
        if (self::$config['log'] & $level) {
            $time = (new DateTime())->format(DateTime::ISO8601);

            switch ($level) {
                case self::LOG_ERROR:
                    $message = "$time [ERROR] $message";
                    break;
                case self::LOG_WARNING:
                    $message = "$time [WARNING] $message";
                    break;
                case self::LOG_INFO:
                    $message = "$time [INFO] $message";
                    break;
                case self::LOG_DEBUG:
                    $message = "$time [DEBUG] $message";
                    break;
            }
            print_r($message . "\n");
        }
    }

    /**
     * SyncGitHub constructor, will load the configuration file.
     */
    public function __construct()
    {
        if (!file_exists('config.php')) {
            throw new Exception ('config.php does not exist, please create a config. There is an example included.');
        }
        self::$config = include_once('config.php');
        R::setup(self::$config['database'], null, null, true);
    }

    /**
     * Runs the sync.
     */
    public function run()
    {
        self::log(self::LOG_INFO, 'Starting sync.');
        $model = new SyncGitHub();

        // Create new tasks
        $openTasks = $model->getAllNewOpenTasks();
        foreach ($openTasks as $task) {
            self::log(self::LOG_INFO, "Creating issue ($task->id):  $task->name");
            self::log(self::LOG_INFO, 'Labels: ' . implode(', ', $model->getIssueLabelsFromTask($task)));
            R::begin();
            try {
                $issue = $model->createIssue($model->getIssueFromTask($task));
                $model->saveSync($model->createSyncBean($task, $issue));
                R::commit();
            } catch (Exception $e) {
                self::log(self::LOG_ERROR, 'Failed to create task, rolling back changes.');
                self::log(self::LOG_DEBUG, $e->getMessage());
                if(isset($issue)) {
                    $model->deleteIssue($issue);
                }
                R::rollback();
            }
        }

        // Update existing tasks
        $editSyncs = $model->getAllOpenSyncGitHub();
        foreach ($editSyncs as $sync) {
            $task = $sync->task;

            if ($model->getTaskHash($task) != $sync->checksum) {
                self::log(self::LOG_INFO, "Updating issue from task ($task->id) to issue ($sync->issue_id)");
                R::begin();
                try {
                    $issue = $model->getIssueFromTask($task);
                    $issue['id'] = $sync->issue_id;
                    $model->updateIssue($issue);

                    $sync->checksum = $model->getTaskHash($task);
                    $model->saveSync($sync);
                    R::commit();
                } catch (Exception $e) {
                    self::log(self::LOG_ERROR, 'Failed to update issue, rolling back changes.');
                    self::log(self::LOG_DEBUG, $e->getMessage());
                    if (isset($issue)) {
                        $model->deleteIssue($issue);
                    }
                    R::rollback();
                }
            }
        }

        self::log(self::LOG_INFO, 'Sync ended.');
    }
}