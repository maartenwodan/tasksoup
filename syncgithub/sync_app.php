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

        if ($model->checkGitHubApiRateLimit()) {
            // Create new tasks
            $openTasks = $model->getAllNewOpenTasks();
            foreach ($openTasks as $task) {
                // First compare hashes with open tickets, if the option is enabled.
                if (self::$config['preventCopies']) {
                    $openSync = $model->getOpenSyncGitHubByHash($model->getTaskHash($task));

                    if ($model->isEmpty($task)) {
                        // Task is deemed too empty, continue regardless if it is a copied or new task.
                        self::log(self::LOG_WARNING, "Task ($task->id) is empty, short title and no description or notes. Doing nothing.");
                        continue;
                    } elseif (!is_null($openSync) && $openSync->task->period_id != $task->period_id) {
                        // Found match.
                        $copy = $openSync->task;
                        self::log(self::LOG_INFO, "Found copied task ($copy->id)->($task->id):  $copy->name.");
                        self::log(self::LOG_INFO, "Disabling old task, enabling new one...");
                        R::begin();
                        try {
                            $model->saveSync($model->createSyncBean($task, ['id' => $openSync->issue_id]));
                            $openSync->issue_id = null;
                            $openSync->done = 1;
                            $model->saveSync($openSync);
                            R::commit();
                        } catch (Exception $e) {
                            self::log(self::LOG_ERROR, "Caught exception while disabling old task, or enabling new. Doing nothing.");
                            self::log(self::LOG_DEBUG, $e->getMessage());
                            R::rollback();
                        }
                        continue;
                    }
                }

                // Creating ticket.
                self::log(self::LOG_INFO, "Creating issue ($task->id):  $task->name");
                self::log(self::LOG_INFO, 'Labels: ' . implode(', ', $model->getIssueLabelsFromTask($task)));
                R::begin();
                try {
                    $issue = $model->createIssue($model->getIssueFromTask($task));
                    $model->saveSync($model->createSyncBean($task, $issue));
                    R::commit();
                } catch (Exception $e) {
                    self::log(self::LOG_ERROR, 'Failed to create task, rolling back changes.');
                    if (isset($issue['id'])) {
                        self::log(self::LOG_ERROR, "Issue ($issue[id]) was created and will now be closed on github.");
                        $model->closeIssue($issue, 'Failed to sync new task, closing. Please check sync log for errors.');
                    }
                    self::log(self::LOG_DEBUG, $e->getMessage());
                    R::rollback();
                }
            }

            // Update existing tasks
            $editSyncs = $model->getAllOpenSyncGitHub();
            foreach ($editSyncs as $sync) {
                $task = $sync->task;

                // Reopen any issues that were closed.
                if ($sync->done == 1 && !($task->done)) {
                    self::log(self::LOG_INFO, "Reopening issue from task ($task->id) to issue ($sync->issue_id).");
                    R::begin();
                    try {
                        $model->reOpenIssue(['id' => $sync->issue_id], 'reopened');
                        $sync->done = 0;
                        $model->saveSync($sync);
                        R::commit();
                    } catch (Exception $e) {
                        self::log(self::LOG_ERROR, "Failed to reopen issue ($sync->issue_id), rolling back changes.");
                        self::log(self::LOG_DEBUG, $e->getMessage());
                        R::rollback();
                    }
                }
                // Actual update
                if ($model->getTaskHash($task) != $sync->checksum) {
                    self::log(self::LOG_INFO, "Updating issue from task ($task->id) to issue ($sync->issue_id).");
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
                        R::rollback();
                    }
                }
            }

            // Close existing tasks
            $closedSyncs = $model->getClosedOrDeletedSyncs();
            foreach ($closedSyncs as $sync) {
                $task = $sync->task;
                $method = 'closed';

                if ($task->id === 0) {
                    // Task is deleted.
                    $method = 'deleted';
                }
                self::log(self::LOG_INFO, "Closing issue  ($sync->issue_id), because task ($task->id) was $method.");

                R::begin();
                try {
                    // Prepare sync, Redbean automatically removes task_id, prevent that.
                    $sync->done = 1;
                    $tmpTaskId = $sync->task_id;
                    $sync->task = null;
                    $sync->task_id = $tmpTaskId;

                    $model->saveSync($sync);
                    $model->closeIssue(['id' => $sync->issue_id], $method);
                    R::commit();
                } catch (Exception $e) {
                    self::log(self::LOG_ERROR, 'Failed to close issue, rolling back changes.');
                    self::log(self::LOG_DEBUG, $e->getMessage());
                    R::rollback();
                }
            }
        };
        self::log(self::LOG_INFO, 'Sync ended.');
    }
}