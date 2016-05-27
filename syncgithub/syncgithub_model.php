<?php

/**
 * Class SyncGitHub
 *
 * This is the 'model' class for the syncgithub table. This does not use the SimpleModel class provided by RedBean
 * because we are mostly just collecting basic sync logic in here which we translate to the database. In that sense,
 * this is more of a business logic model then a real SimpleModel.
 */
class SyncGitHub
{
    /**
     * Contains the github api client, will be instantiated at construction for now.
     * @var \Github\Client
     */
    public $gitClient;

    public function __construct()
    {
        if (SyncApp::$config['gitCache']) {
            // Cached github client, or select directly which cache you want to use
            $cache = new \Github\HttpClient\CachedHttpClient();
            $cache->setCache(new \Github\HttpClient\Cache\FilesystemCache(SyncApp::$config['gitCacheLocation']));
            $this->gitClient = new \Github\Client($cache);
        } else {
            $this->gitClient = new \Github\Client();
        }
        $this->gitClient->authenticate(SyncApp::$config['gitToken'], null, Github\Client::AUTH_HTTP_TOKEN);
    }

    public function saveSync($syncBean)
    {
        try {
            R::store($syncBean);
        } catch (Exception $e) {
            SyncApp::log(SyncApp::LOG_ERROR, 'Can\'t save to sync table.');
            SyncApp::log(SyncApp::LOG_DEBUG, "id: $syncBean->id; task_id: $syncBean->task_id; issue_id: $syncBean->issue_id;");
            SyncApp::log(SyncApp::LOG_DEBUG, $e->getMessage());
            throw $e;
        }
    }

    public function createSyncBean($taskBean, $issue)
    {
        if (!isset($issue['id'])) {
            throw new Exception('Issue id is not set. Can\'t create a sync table entry.');
        }
        $syncBean = R::dispense('syncgithub');
        $syncBean->task_id = $taskBean->id;
        $syncBean->issue_id = $issue['id'];
        $syncBean->checksum = $this->getTaskHash($taskBean);
        $syncBean->done = 0;
        return $syncBean;
    }

    public function getSyncBeanFromTask($taskBean)
    {
        return R::findOne('syncgithub', 'task_id = ? AND issue_id NOT NULL', [$taskBean->id]);
    }

    public function getSyncBeanFromIssue($issue)
    {
        return R::findOne('syncgithub', 'issue_id = ?', [$issue['id']]);
    }

    public function deleteSync($syncBean)
    {
        try {
            R::trash($syncBean);
        } catch (Exception $e) {
            SyncApp::log(SyncApp::LOG_ERROR, 'Can\'t delete item from the sync table.');
            SyncApp::log(SyncApp::LOG_DEBUG, "id: $syncBean->id; task_id: $syncBean->task_id; issue_id: $syncBean->issue_id;");
            SyncApp::log(SyncApp::LOG_DEBUG, $e->getMessage());
            throw $e;
        }
    }

    public function saveTask($taskBean)
    {
        try {
            R::store($taskBean);
        } catch (Exception $e) {
            $method = $taskBean->id == 0 ? 'create' : 'update';
            SyncApp::log(SyncApp::LOG_ERROR, "Can't $method task.");
            SyncApp::log(SyncApp::LOG_DEBUG, "id: $taskBean->id; name: $taskBean->name;");
            SyncApp::log(SyncApp::LOG_DEBUG, $e->getMessage());
            throw $e;
        }
    }

    public function deleteTask($taskBean)
    {
        try {
            R::trash($taskBean);
        } catch (Exception $e) {
            SyncApp::log(SyncApp::LOG_ERROR, "Can't delete task.");
            SyncApp::log(SyncApp::LOG_DEBUG, "id: $taskBean->id; name: $taskBean->name;");
            SyncApp::log(SyncApp::LOG_DEBUG, $e->getMessage());
            throw $e;
        }
    }

    public function createIssue($issue)
    {
        try {
            $newIssue = $this->gitClient->api('issue')
                ->create(SyncApp::$config['gitLocation'], SyncApp::$config['gitRepo'], $issue);
            // Number maps straight to the issue id.
            $issue['id'] = $newIssue['number'];
        } catch (Exception $e) {
            SyncApp::log(SyncApp::LOG_ERROR, "Failed to create issue on github.");
            SyncApp::log(SyncApp::LOG_DEBUG, $e->getMessage());
            throw $e;
        }
        return $issue;
    }

    public function updateIssue($issue)
    {
        if (!isset($issue['id'])) {
            throw new Exception('Issue id is not set. Can\'t update an issue without an id. This could be a database issue.');
        }
        try {
            $this->gitClient->api('issue')
                ->update(SyncApp::$config['gitLocation'], SyncApp::$config['gitRepo'], $issue['id'], $issue);
        } catch (Exception $e) {
            SyncApp::log(SyncApp::LOG_ERROR, "Failed to update issue ($issue[id]) on github.");
            SyncApp::log(SyncApp::LOG_DEBUG, $e->getMessage());
            throw $e;
        }
        return $issue;
    }

    public function deleteIssue($issue)
    {

    }

    /**
     * Returns all open tasks in tasksoup, regardless if they are synced with github or not.
     *
     * @return array
     */
    public function getAllOpenTasks()
    {
        return R::find('task', 'done IS NULL OR done = 0');
    }

    /**
     * Gets all newly created open tasks in tasksoup. The definition of new is, existing in the task table, but not in
     * the githubsync table. Where done IS NULL or 0, and the period is OPEN (closed IS NULL or 0).
     *
     * @return array
     */
    public function getAllNewOpenTasks()
    {
        $records = R::getAll(<<<SQL
SELECT task.* 
FROM task
LEFT JOIN period ON task.period_id = period.id
WHERE task.id NOT IN (
    SELECT s.task_id FROM syncgithub s
  )
  AND (done IS NULL OR done = 0)
  AND (period.closed IS NULL OR period.closed = 0)
SQL
        );
        return R::convertToBeans('task', $records);
    }

    /**
     * This will get all open tasks, that are still open in the sync as well. Even if it means they are closed already
     * in tasksoup we will get them. This way, even tasks that are about to be closed on github are being updated to the
     * latest status. This will return an array of beans, and can be optimised by returning the sync table with it, but
     * no need for it now since it is a console application.
     *
     * @return array
     */
    public function getAllOpenSyncGitHub()
    {
        $records = R::getAll(<<<SQL
SELECT syncgithub.* 
FROM task
INNER JOIN syncgithub ON syncgithub.task_id = task.id
WHERE syncgithub.done = 0;
SQL
        );
        return R::convertToBeans('syncgithub', $records);
    }

    /**
     * Makes a simple comment from the given bean, this can be used to describe the ticket. It does not contain the
     * title, that will be handled separately.
     *
     * @param \RedBeanPHP\OODBBean $taskBean
     * @return string
     */
    public function getSimplifiedComment($taskBean)
    {
        $tasksoupUrl = SyncApp::$config['tasksoupUrl'];
        $comment = <<<COMMENT
**Description:**
$taskBean->description

**Notes:**
$taskBean->notes

**Client:** _{$taskBean->client}_
**Contact:** _{$taskBean->contact}_
**Contact:** _{$taskBean->project}_
**Contact:** _{$taskBean->budget}_
**Due:** _{$taskBean->due}_

[Task on tasksoup]({$tasksoupUrl}?c=edittask&id={$taskBean->id})
COMMENT;
        return $comment;
    }

    /**
     * Makes a sha1 hash of the simplifiedComment send to Github, and a few more fields that are synced.
     *
     * @param \RedBeanPHP\OODBBean $taskBean
     * @return string
     */
    public function getTaskHash($taskBean)
    {
        return sha1($this->getSimplifiedComment($taskBean)
            . $taskBean->title
            . $taskBean->type
        );
    }

    /**
     * Makes the hash of the github issue body, title, and label.
     *
     * @param $issue
     * @return string
     */
    public function getIssueHash($issue)
    {
        return sha1($issue['body']
            . $issue['title']
            . $this->getTaskTypeFromIssue($issue)
        );
    }

    /**
     * Returns the integer representation of the github label, that reflects the integer used in the task table in
     * tasksoup. Returns false if no mapping is found. Pay attention, since this can also return 0.
     *
     * @param $issue
     * @return int|bool
     */
    public function getTaskTypeFromIssue($issue)
    {
        $labelToType = array_flip($this->config['labelTypeMap']);
        foreach ($issue['labels'] as $label) {
            if (isset($labelToType[$label])) {
                return $labelToType[$label];
            }
        }
        return false;
    }

    /**
     * Returns an array with the fields set to be used straight as an issue in the github client api. This also empties
     * the assignee by default, this is a item.
     *
     * @todo Base assignee on a hours mapped to a task, most hours is the assignee. Github soon has multiple assignees.
     * @param $taskBean
     * @return array
     */
    public function getIssueFromTask($taskBean)
    {
        return [
            'title' => $taskBean->name,
            'body' => $this->getSimplifiedComment($taskBean),
            'labels' => $this->getIssueLabelsFromTask($taskBean),
            'assignee' => '',
        ];
    }

    /**
     * Returns an array with labels to set in the github issue based on the given task. This also sets the tasksoup
     * label automatically if that option is turned on in the config.
     *
     * @param \RedBeanPHP\OODBBean $taskBean
     * @return array
     */
    public function getIssueLabelsFromTask($taskBean)
    {
        $labels = [];
        if (SyncApp::$config['labelTasksoup']) {
            $labels[] = 'tasksoup';
        }
        if (SyncApp::$config['labelType'] && isset($taskBean->type)) {
            if (isset(SyncApp::$config['labelTypeMap'][$taskBean->type])) {
                $labels[] = SyncApp::$config['labelTypeMap'][$taskBean->type];
            } else {
                SyncApp::log(SyncApp::LOG_WARNING, "Unknown task type '$taskBean->type', update labelTypeMap.");
            }
        } elseif (!isset($taskBean->type)) {
            SyncApp::log(SyncApp::LOG_WARNING, "Task type for task not set, please update task ($taskBean->id) $taskBean->name.");
        }
        return $labels;
    }
}