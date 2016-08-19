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
            $time = new DateTime();
            $time = $time->format(DateTime::ISO8601);

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
            // From tasksoup to github
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
                            $issueId = $openSync->issue_id;
                            $openSync->issue_id = null;
                            $openSync->done = 1;
                            $model->saveSyncBean($openSync);
                            $model->saveSyncBean($model->createSyncBean($task, array('number' => $issueId)));
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
                    $model->saveSyncBean($model->createSyncBean($task, $issue));
                    R::commit();
                } catch (Exception $e) {
                    self::log(self::LOG_ERROR, 'Failed to create issue, rolling back changes.');
                    if (isset($issue['number'])) {
                        self::log(self::LOG_ERROR, "Issue ({$issue['number']}) was created and will now be closed on github.");
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
                        $model->reOpenIssue(array('number' => $sync->issue_id), 'reopened');
                        $sync->done = 0;
                        $model->saveSyncBean($sync);
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
                        $issue['number'] = $sync->issue_id;
                        $model->updateIssue($issue);

                        $sync->checksum = $model->getTaskHash($task);
                        $model->saveSyncBean($sync);
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
                self::log(self::LOG_INFO, "Closing issue  ($sync->issue_id), because task ($sync->task_id) was $method.");

                R::begin();
                try {
                    // Prepare sync, Redbean automatically removes task_id, prevent that.
                    $sync->done = 1;
                    $tmpTaskId = $sync->task_id;
                    $sync->task = null;
                    $sync->task_id = $tmpTaskId;

                    $model->saveSyncBean($sync);
                    $model->closeIssue(array('number' => $sync->issue_id), $method);
                    R::commit();
                } catch (Exception $e) {
                    self::log(self::LOG_ERROR, 'Failed to close issue, rolling back changes.');
                    self::log(self::LOG_DEBUG, $e->getMessage());
                    R::rollback();
                }
            }
        };

        // From github to tasksoup
        $issues = $model->getAllIssues(true); // '2016-07-05 18:00:00'
        self::log(self::LOG_INFO, "Going to try and check if " . count($issues) . " issues need to be synced.");
        foreach ($issues as $issue) {
            $syncBean = $model->getSyncBeanFromIssue($issue);
            // Check if the syncbean exists, if it does, we should update the issue, if not, we should create a task.
            if ($syncBean) {
                // Update the task if the hash differentiates, if so update the task.
                $checksum = $model->getIssueHash($issue);

                // Check if state has changed, with ones and zeroes for easy comparison to our table.
                $issueIsDone = $issue['state'] == 'closed' ? 1 : 0;

                // Updating task with new info here.
                if (!$issueIsDone && $syncBean->checksum != $checksum && $syncBean->task && $syncBean->task->id) {
                    self::log(self::LOG_INFO, "Found modified issue ({$syncBean->issue_id}), so we can update task ({$syncBean->task_id}).");
                    R::begin();
                    try {
                        $taskBean = $model->getTaskBeanFromIssue($issue, $syncBean->task);
                        $model->saveTask($taskBean);
                        $syncBean->checksum = $model->getTaskHash($taskBean);
                        $model->saveSyncBean($syncBean);
                        R::commit();
                    } catch (Exception $e) {
                        self::log(self::LOG_ERROR, 'Failed to update task, rolling back changes.');
                        self::log(self::LOG_DEBUG, $e->getMessage());
                        R::rollback();
                    }
                }

                // Closing / Reopening here.
                if ($issueIsDone != $syncBean->done) {
                    self::log(self::LOG_INFO, "Found state change for issue ({$syncBean->issue_id}) and task ({$syncBean->task_id}), state is now {$issue['state']}.");
                    if ($issueIsDone) {
                        // Just closing the task and the sync and it's done!
                        $syncBean->done = 1;
                        $model->saveSyncBean($syncBean);

                        // Only update task if it is attached and not deleted yet.
                        if ($syncBean->task_id !== 0) {
                            $task = $syncBean->task;
                            $task->done = 1;
                            $task->progress = 100;
                            $task->end = date('Y-m-d');
                            $model->saveTask($task);
                        }
                    } else {
                        // Issue got reopened, what's going on! There are a few possibilities:
                        // 1. Issue reopened, task is closed, period is current. => Just open task.
                        // 2. Issue reopened, task was deleted. This means we have to recreate the task again.
                        // 3. Issue reopened, task is closed, period has past => Copy task to nextPeriod
                        // 4. Issue reopened, task is closed, period has yet to begin => Open again.
                        // 5. Issue reopened, task is closed, has no period! Put it in next period, open again.
                        self::log(self::LOG_INFO, "Issue reopened ({$issue['number']}), checking what state we are in...");

                        // actual saving happens at the end of this else statement, we can set this to false if an
                        // error occurred so that we do not save anything.
                        $succesfulReopening = true;

                        // Redbean automatically creates a task when the old one got deleted, with a proper id. Id
                        // should always be filled and 0 so check on that.
                        if ($syncBean->task && $syncBean->task->id) {
                            $taskBean = $syncBean->task;
                            $period = $taskBean->period;
                            if ($period) {
                                $start = DateTime::createFromFormat('Y-m-d', $period->start);
                                $end = DateTime::createFromFormat('Y-m-d', $period->end);
                                $now = new Datetime('now');

                                if (($end > $now && $start < $now) || $start > $now) {
                                    // Situation 1. Current period
                                    // Situation 4. Period has yet to start.
                                    self::log(self::LOG_INFO, "Task ({$taskBean->id}) is in valid period, reopening task.");
                                    $taskBean->progress = 0;
                                    $taskBean->done = 0;
                                } elseif ($end < $now) {
                                    // Situation 3. Period has passed..
                                    self::log(self::LOG_INFO, "Task ({$taskBean->id}) is in an old period, copying task to next period.");
                                    $period = $model->getNextPeriod();
                                    if ($period) {
                                        $taskBean->id = 0;
                                        $taskBean->progress = 0;
                                        $taskBean->done = 0;
                                        $taskBean->period = $period;
                                        $syncBean->task = $taskBean;
                                    } else {
                                        self::log(self::LOG_ERROR, "Can't find a period to copy newly reopened issue too, didn't do anything.");
                                        $succesfulReopening = false;
                                    }
                                } else {
                                    // It shouldn't get here, something fishy happened.
                                    self::log(self::LOG_ERROR, "Something fishy about period with id ({$period->id}), didn't do anything.");
                                    $succesfulReopening = false;
                                }
                            } else {
                                // Situation 5. Invalid period.
                                self::log(self::LOG_INFO, "Task ({$taskBean->id}) is in non existing period, moving task to next period.");
                                $period = $model->getNextPeriod();
                                if ($period) {
                                    $taskBean->period = $period;
                                    $taskBean->progress = 0;
                                    $taskBean->done = 0;
                                } else {
                                    self::log(self::LOG_ERROR, "Can't find a period to copy newly reopened issue too, create a new period, didn't do anything.");
                                    $succesfulReopening = false;
                                }
                            }
                        } else {
                            // Situation 2. Deleted task.
                            try {
                                $taskBean = $model->getTaskBeanFromIssue($issue);
                                $syncBean->task = $taskBean;
                                self::log(self::LOG_INFO, "Task for issue ({$issue['number']}) got deleted, creating new task for reopened issue : $taskBean->name");
                            } catch (Exception $e) {
                                self::log(self::LOG_ERROR, 'Failed to create new task, will not do anything.');
                                self::log(self::LOG_DEBUG, $e->getMessage());
                                $succesfulReopening = false;
                            }
                        }

                        // Actual saving happens here.
                        if ($succesfulReopening) {
                            R::begin();
                            try {
                                $model->saveTask($taskBean);
                                $syncBean->done = 0;
                                $model->saveSyncBean($syncBean);
                                R::commit();
                            } catch (Exception $e) {
                                self::log(self::LOG_ERROR, 'Failed to reopen task.');
                                self::log(self::LOG_DEBUG, $e->getMessage());
                                R::rollback();
                            }
                        }
                    }
                }
            } elseif ($issue['state'] == 'open') {
                // No syncBean, so we are creating a new task in tasksoup, and updating the body of the issue.
                R::begin();
                try {
                    $taskBean = $model->getTaskBeanFromIssue($issue);
                    self::log(self::LOG_INFO, "Creating task for issue ({$issue['number']}): $taskBean->name");
                    $model->saveTask($taskBean);
                    $model->saveSyncBean($model->createSyncBean($taskBean, $issue));

                    $taskIssue = $model->getIssueFromTask($taskBean);
                    $taskIssue['number'] = $issue['number'];
                    unset($taskIssue['assignee']);
                    unset($taskIssue['assignees']);
                    $model->updateIssue($taskIssue);
                    R::commit();
                } catch (Exception $e) {
                    self::log(self::LOG_ERROR, 'Failed to create task, rolling back changes.');
                    self::log(self::LOG_DEBUG, $e->getMessage());
                    R::rollback();
                }
            }
        }

        self::log(self::LOG_INFO, 'Sync ended.');
    }
}