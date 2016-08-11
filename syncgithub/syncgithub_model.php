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

    /**
     * SyncGitHub constructor.
     *
     * Creates the GitHub client api, and sets up cache if required by the configuration.
     */
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

    /**
     * Saves a github sync bean to the database.
     *
     * @param \RedBeanPHP\OODBBean $syncBean
     * @throws Exception
     */
    public function saveSyncBean($syncBean)
    {
        try {
            $syncBean->modified = date('Y-m-d H:i:s');
            R::store($syncBean);
        } catch (Exception $e) {
            SyncApp::log(SyncApp::LOG_ERROR, 'Can\'t save to sync table.');
            SyncApp::log(SyncApp::LOG_DEBUG, "id: $syncBean->id; task_id: $syncBean->task_id; issue_id: $syncBean->issue_id;");
            SyncApp::log(SyncApp::LOG_DEBUG, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Creates a sync bean from an issue array, and a task bean. This can be an issue array straight from the api or
     * created from a task bean. The issue only requires the id to be set, the rest can be left empty.
     *
     * By default this is created as a completely new sync bean, so done 0 and no id.
     *
     * @param \RedBeanPHP\OODBBean $taskBean
     * @param array $issue
     * @return \RedBeanPHP\OODBBean
     * @throws Exception
     */
    public function createSyncBean($taskBean, $issue)
    {
        if (!array_key_exists('number', $issue)) {
            throw new Exception('Issue id is not set. Can\'t create a sync table entry.');
        }
        $syncBean = R::dispense('syncgithub');
        $syncBean->task_id = $taskBean->id;
        $syncBean->issue_id = $issue['number'];
        $syncBean->checksum = $this->getTaskHash($taskBean);
        $syncBean->done = 0;
        $syncBean->modified = date('Y-m-d H:i:s');
        return $syncBean;
    }

    /**
     * Returns a sync bean if it finds a sync bean for the given task bean.
     *
     * @param \RedBeanPHP\OODBBean $taskBean
     * @return \RedBeanPHP\OODBBean
     */
    public function getSyncBeanFromTask($taskBean)
    {
        return R::findOne('syncgithub', 'task_id = ? AND issue_id NOT NULL', array($taskBean->id));
    }

    /**
     * Returns a sync bean if it finds a sync bean for the given issue, only id has to be set for the issue.
     *
     * @param array $issue
     * @return \RedBeanPHP\OODBBean
     */
    public function getSyncBeanFromIssue($issue)
    {
        return R::findOne('syncgithub', 'issue_id = ?', array($issue['number']));
    }

    /**
     * Removes a sync bean from the database.
     *
     * _Warning_ When removed and the task still exists, it will recreate the issue on github. If the issue still exists
     * then the task will be recreated depending on sync settings (master / slave).
     *
     * @param \RedBeanPHP\OODBBean $syncBean
     * @throws Exception
     */
    public function deleteSyncBean($syncBean)
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

    /**
     * Saves a task to the database.
     *
     * @param \RedBeanPHP\OODBBean $taskBean
     * @throws Exception
     */
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

    /**
     * Deletes a task, probably deprecated because we shouldn't delete tasks in this api.
     *
     * @deprecated Marked as such because we don't want to delete tasks in this sync since github doesn't delete issues.
     * @param \RedBeanPHP\OODBBean $taskBean
     * @throws Exception
     */
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

    /**
     * Creates the issue given on GitHub via the API. Returns the issue array given, but filled with the created issue
     * id on github.
     *
     * _Warning_ Actually calls the GitHub API.
     *
     * @param array $issue
     * @return array
     * @throws Exception
     */
    public function createIssue($issue)
    {
        try {
            $newIssue = $this->gitClient->api('issue')
                ->create(SyncApp::$config['gitLocation'], SyncApp::$config['gitRepo'], $issue);
            // Number maps straight to the issue id.
            $issue['number'] = $newIssue['number'];
        } catch (Exception $e) {
            SyncApp::log(SyncApp::LOG_ERROR, "Failed to create issue on github.");
            SyncApp::log(SyncApp::LOG_DEBUG, $e->getMessage());
            throw $e;
        }
        return $issue;
    }

    /**
     * Updates the given issue on GitHub via the API. Returns the issue as it was given.
     *
     * _Warning_ Actually calls the GitHub API.
     *
     * @param array $issue
     * @return array
     * @throws Exception
     */
    public function updateIssue($issue)
    {
        if (!isset($issue['number'])) {
            throw new Exception('Issue id is not set. Can\'t update an issue without an id. This could be a database issue.');
        }
        try {
            $this->gitClient->api('issue')
                ->update(SyncApp::$config['gitLocation'], SyncApp::$config['gitRepo'], $issue['number'], $issue);
        } catch (Exception $e) {
            SyncApp::log(SyncApp::LOG_ERROR, "Failed to update issue ({$issue['number']}) on github.");
            SyncApp::log(SyncApp::LOG_DEBUG, $e->getMessage());
            throw $e;
        }
        return $issue;
    }

    /**
     * Closes the issue and creates a comment on GitHub via the API. Returns the issue as given, but now with 'state' =
     * 'closed'. Reason is the reason the issue was closed and is left as a comment.
     *
     * _Warning_ Actually calls the GitHub API.
     *
     * @param array $issue
     * @param string $reason
     * @return array
     * @throws Exception
     */
    public function closeIssue($issue, $reason)
    {
        if (!isset($issue['number'])) {
            throw new Exception('Issue id is not set. Can\'t close an issue without an id. This could be a database issue.');
        }
        $issue['state'] = 'closed';
        $issue = $this->updateIssue($issue);
        $this->createIssueComment($issue, array(
            'title' => $reason,
            'body' => "Closing issue ({$issue['number']}), because task on tasksoup was $reason."
        ), $reason);
        return $issue;
    }

    /**
     * When an issue seems closed on GitHub, but should actually be opened, you can reopen it here. It also leaves a
     * nice little comment behind on the issue so we know what happened. This can be called when the task on tasksoup
     * is reopened.
     *
     * Returns the issue give, but now with 'state' = 'open'.
     *
     * _Warning_ Actually calls the GitHub API.
     *
     * @param array $issue
     * @return array
     * @throws Exception
     */
    public function reOpenIssue($issue)
    {
        if (!isset($issue['number'])) {
            throw new Exception('Issue id is not set. Can\'t close an issue without an id. This could be a database issue.');
        }
        $issue['state'] = 'open';
        $issue = $this->updateIssue($issue);
        $this->createIssueComment($issue, array(
            'title' => 'reopened',
            'body' => "Reopening issue ({$issue['number']}), because task on tasksoup was reopened."
        ), 'reopened');
        return $issue;
    }

    /**
     * Checks the github rate, and if it is exceeded throws an exception.
     *
     * _Warning_ Actually calls the GitHub API. But doesn't count as of yet for your total.
     *
     * @todo Make this a variable that can be accessed easily anywhere in the model. This way we can check it before doing anything.     *
     * @todo Do not exit here.
     * @return bool True if the rate is fine, false if the rate is exceeded.
     * @throws Exception
     */
    public function checkGitHubApiRateLimit()
    {
        try {
            $rateLimit = $this->gitClient->api('rate_limit')->getRateLimits();
            $remaining = $rateLimit['resources']['core']['remaining'];
            $limit = $rateLimit['resources']['core']['limit'];
            $resetOn = new DateTime('@' . $rateLimit['resources']['core']['reset']);
            $resetOn = $resetOn->format(DateTime::ISO8601);
            SyncApp::log(SyncApp::LOG_INFO, "Github rate limit: $remaining/$limit, resets on $resetOn");
        } catch (Exception $e) {
            SyncApp::log(SyncApp::LOG_ERROR, "Can't seem to connect to the GitHub api. Stopping.");
            SyncApp::log(SyncApp::LOG_DEBUG, $e->getMessage());
            exit();
        }
        if ($remaining == 0) {
            SyncApp::log(SyncApp::LOG_ERROR, "Rate limit exceeded.");
            return false;
        } elseif ($remaining < 100) {
            SyncApp::log(SyncApp::LOG_WARNING, "Almost out of calls to make to the GitHub api.");
        }
        return true;
    }

    /**
     * Leaves a comment on the given issue on Github. Comment array exists of a non mandatory 'title' and a mandatory
     * 'body' key value pair.
     *
     * _Warning_ Actually calls the GitHub API.
     *
     * @param array $issue
     * @param array $comment
     * @param string $reason Fill in a reason for the comment. This will only appear in the error log if creation fails.
     * @throws Exception when an issue id is not set.
     */
    public function createIssueComment($issue, $comment, $reason = 'unknown')
    {
        $comment['title'] = ucfirst($comment['title']);
        if (!isset($issue['number'])) {
            throw new Exception('Issue id is not set. Can\'t comment on an issue without an id. This could be a database issue.');
        }
        try {
            $this->gitClient->api('issue')->comments()
                ->create(SyncApp::$config['gitLocation'], SyncApp::$config['gitRepo'], $issue['number'], $comment);
        } catch (Exception $e) {
            SyncApp::log(SyncApp::LOG_ERROR, "Failed to leave a comment on issue ({$issue['number']}) on github, for $reason reasons.");
            SyncApp::log(SyncApp::LOG_DEBUG, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get's all the issues straight from GitHub. Param $since defaults to false, which means getting all issues. When
     * there is lots and lots of issues to be synced, it might have a race condition where between the start of the
     * script and the end, an issue will be changed. This issue won't be taken into account when syncing because the
     * last modified already took place, because of this you can configure a 'modifiedDiscrepancy' value in your config.
     *
     * _Warning_ Actually calls the GitHub API.
     *
     * @param bool|string $since Date time in 'Y-m-d H:i:s', or false if you need all issues, or true if you only need
     * the issues since the last modified sync.
     * @throws Exception when it can't get the issues from the api.
     * @return array|mixed
     */
    public function getAllIssues($since = false)
    {
        $paginator = new Github\ResultPager($this->gitClient);
        $api = $this->gitClient->api('issue');

        $params = array('state' => 'all');
        if ($since === true) {
            $syncBean = R::findOne('syncgithub', 'ORDER BY modified DESC');
            if ($syncBean) {
                $params['since'] = DateTime::createFromFormat('Y-m-d H:i:s', $syncBean->modified)
                    ->modify(SyncApp::$config['modifiedDiscrepancy'])
                    ->format(DateTime::ISO8601);
            } else {
                $datetime = new DateTime('now');
                $params['since'] = $datetime
                    ->modify(SyncApp::$config['modifiedDiscrepancy'])
                    ->format(DateTime::ISO8601);;
            }
        } elseif ($since) {
            $params['since'] = DateTime::createFromFormat('Y-m-d H:i:s', $since)
                ->modify(SyncApp::$config['modifiedDiscrepancy'])
                ->format(DateTime::ISO8601);
        }

        try {
            $result = $paginator->fetchAll($api, 'all', array(
                SyncApp::$config['gitLocation'],
                SyncApp::$config['gitRepo'],
                $params
            ));
        } catch (Exception $e) {
            $since = var_export($since, true);
            SyncApp::log(SyncApp::LOG_ERROR, "Could not retrieve any issues from GitHub, since was set to '$since'");
            throw $e;
        }
        // Removing any pull requests from the result array.
        foreach ($result as $key => $issue) {
            if (isset($issue['pull_request']) && is_array($issue['pull_request'])) {
                unset($result[$key]);
            }
        }
        return $result;
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
     * Returns the next period if it finds one, otherwise it will return null.
     * @return \RedBeanPHP\OODBBean|null
     */
    public function getNextPeriod()
    {
        return R::findOne('period', 'start > ? AND closed = 0', array(date('Y-m-d')));
    }

    /**
     * Returns the latest available period, or null if there are no periods.
     * @return \RedBeanPHP\OODBBean|null
     */
    public function getLatestAvailablePeriod()
    {
        return R::findOne('period', 'closed = 0 ORDER BY end DESC');
    }

    /**
     * This will get all open tasks, that are still open in the sync as well. Even if it means they are closed already
     * in tasksoup we will get them. This way, even tasks that are about to be closed on github are being updated to the
     * latest status. This will return an array of beans, and can be optimised by returning the sync table with it, but
     * no need for it now since it is a console application.
     *
     * In addition, this gets the tasks that are REOPENED in tasksoup, so we may reopen them again in the sync as well.
     *
     * @return array
     */
    public function getAllOpenSyncGitHub()
    {
        $records = R::getAll(<<<SQL
SELECT syncgithub.*
FROM task
  INNER JOIN syncgithub ON syncgithub.task_id = task.id
  LEFT JOIN period ON task.period_id = period.id
WHERE syncgithub.done = 0 
  OR (
    syncgithub.issue_id IS NOT NULL
    AND task.id IS NOT NULL
    AND syncgithub.done = 1
    AND (task.done = 0 OR task.done IS NULL)
    AND (period.closed = 0 OR period.closed IS NULL)
  )
SQL
        );
        return R::convertToBeans('syncgithub', $records);
    }

    /**
     * This will search the database for any open sync items by hash. It won't search through the closed syncs. This is
     * to detect if a similar ticket is open in sync, aka a copy. Returns a bean if found, null if nothing is found.
     *
     * @param string $hash
     * @return \RedBeanPHP\OODBBean|null
     */
    public function getOpenSyncGitHubByHash($hash)
    {
        return R::findOne('syncgithub', 'checksum = ? AND (done IS NULL OR done = 0)', array($hash));
    }

    /**
     * Returns all open sync items that either have:
     *  - A closed task attached now.
     *  - A closed period attached now.
     *  - No task attached at all.
     *
     * Careful when checking tasks with these sync items, because there might be deleted items in there that do not have
     * a task attached anymore.
     *
     * @return array
     */
    public function getClosedOrDeletedSyncs()
    {
        $records = R::getAll(<<<SQL
SELECT syncgithub.* 
FROM syncgithub
LEFT JOIN task ON  syncgithub.task_id = task.id
LEFT JOIN period ON task.period_id = period.id
WHERE (syncgithub.done = 0 OR syncgithub.done IS NULL)
  AND (period.closed = 1 OR task.done = 1 OR task.id IS NULL)
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
        $comment = <<<COMMENT
**Description:**
$taskBean->description

**Notes:**
$taskBean->notes

**Client:** _{$taskBean->client}_
**Contact:** _{$taskBean->contact}_
**Project:** _{$taskBean->project}_
**Budget:** _{$taskBean->budget}_
**Due:** _{$taskBean->due}_
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
            . $taskBean->name
            . $taskBean->type
        );
    }

    /**
     * Makes the hash of the github issue body, title, and label. Makes sure the tasksoup url is stripped from the body
     * before performing the hashing operation.
     *
     * @param array $issue
     * @return string
     */
    public function getIssueHash($issue)
    {
        $issue = $this->stripTasksoupUrlFromIssue($issue);
        return sha1($issue['body']
            . $issue['title']
            . $this->getTaskTypeFromIssue($issue)
        );
    }

    /**
     * Creates a task bean from the given issue array. This will try to extract values from the issue body, and if that
     * fails cleanly, it will set the full body as the description of the task.
     *
     * If a task bean is given, instead of creating a new bean, we will update any values in the given bean.
     *
     * If somehow the extraction fails with something else than an empty array, we will throw an exception here because
     * we don't want to create a recursive loop saving a failed detection algorithm in the full description of a task
     * bean.
     *
     * @param array $issue
     * @param \RedBeanPHP\OODBBean|null $taskBean
     * @throws Exception When the information can't be extracted from the issue, or if no period is found.
     * @return \RedBeanPHP\OODBBean
     */
    public function getTaskBeanFromIssue($issue, $taskBean = null)
    {
        if (!$taskBean) {
            $taskBean = R::dispense('task');
        }

        // Check if the issue has the simplified comment format, easier to create a task from there.
        $extracted = $this->getTaskValuesArrayFromIssue($issue);
        if (is_array($extracted) && !empty($extracted)) {
            $taskBean->description = $extracted['description'];
            $taskBean->notes = $extracted['notes'];
            $taskBean->client = $extracted['client'];
            $taskBean->contact = $extracted['contact'];
            $taskBean->project = $extracted['project'];
            $taskBean->budget = $extracted['budget'];
            $taskBean->due = $extracted['due'];
        } elseif (empty($extracted)) {
            // No values extracted, so we fill it ourselves.
            $taskBean->description = $issue['body'];

            // Lets try to guess the contact by the creator of the ticket, otherwise leave empty.
            if (isset($issue['user'])) {
                $contact = $this->getTaskAssigneesFromIssue(array('assignee' => $issue['user']), true);
                if ($contact) {
                    $taskBean->contact = $contact;
                } else {
                    $taskBean->contact = '';
                }
            }
        } else {
            // We can't build a new taskbean from this thing, it seems to have failed the regex, we should not try to
            // sync it.
            SyncApp::log(SyncApp::LOG_ERROR, "Can't extract any values from the issue ({$issue['number']}). No task bean created.");
            throw new Exception("Can't extract any values from the issue ({$issue['number']}). No task bean created.");
        }
        $taskBean->name = $issue['title'];
        $taskBean->type = $this->getTaskTypeFromIssue($issue);
        $taskBean->prio = SyncApp::$config['defaultTaskPriority'];

        // Leave progress alone unless unset.
        if (!$taskBean->progress) {
            $taskBean->progress = 0;
        }

        // Try to set the period, if we can't find the next period then we set it to the last one available, if there is
        // no period available, we fail this, and return false.
        $period = $this->getNextPeriod();
        if (empty($period)) {
            SyncApp::log(SyncApp::LOG_WARNING, 'It seems there is no next period, saving it to the latest open one if found.');

            $period = $this->getLatestAvailablePeriod();
            if (empty($period)) {
                SyncApp::log(SyncApp::LOG_ERROR, "Can't save issue to any period, this issue ({$issue['number']}) will not be synced.");
                throw new Exception("Can't save issue to any period, this issue ({$issue['number']}) will not be synced.");
            }
        }
        $taskBean->period_id = $period->id;

        //label to type
        $taskBean->type = $this->getTaskTypeFromIssue($issue);

        //assignees / work, get the current work first if it is set, then add our assignee if it is not in the current
        //work array.
        $work = array();
        $currentlyAssigned = array();
        if ($taskBean->work) {
            foreach ($taskBean->work as $hours) {
                if ($hours->hours > 0) {
                    $work[] = $hours;
                }
                $currentlyAssigned[] = $work->user_id;
            }
        }
        $assignee = $this->getTaskAssigneesFromIssue($issue, true);
        if ($assignee) {
            $assigneesWithUserIds = $this->getUserIds(array($assignee));

            if (!in_array($assigneesWithUserIds[$assignee], $currentlyAssigned)) {
                $work[] = R::dispense('work')->import(array(
                        'hours' => SyncApp::$config['defaultTaskHours'],
                        'user_id' => $assigneesWithUserIds[$assignee]
                    )
                );
            }
        }
        $taskBean->xownWorkList = $work;

        //created at / updated at
        $taskBean->start = DateTime::createFromFormat(DateTime::ISO8601, $issue['created_at'])->format('Y-m-d');
        $taskBean->end = DateTime::createFromFormat(DateTime::ISO8601, $issue['updated_at'])->format('Y-m-d');

        return $taskBean;
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
        $labelToType = array_flip(SyncApp::$config['labelTypeMap']);
        foreach ($issue['labels'] as $label) {
            if (isset($label['name'])) {
                $label = $label['name'];
            }
            if (isset($labelToType[$label])) {
                return $labelToType[$label];
            }
        }
        SyncApp::log(SyncApp::LOG_WARNING, "No known task type for issue ({$issue['number']}), update labelTypeMap, or assign proper label in github.");
        return false;
    }

    /**
     * This will return a single name, or a list of names, that are in the nameMap in the config mapped to github
     * as assignees. It will only return names, you will still need to find the proper id if you want to create work
     * items out of it.
     *
     * @param array $issue
     * @param bool $matchOne Set to true to only return a single name.
     * @return array|string|bool array when $matchOne is false, a string otherwise, and false if no assignees match.
     */
    public function getTaskAssigneesFromIssue($issue, $matchOne = false)
    {
        $reversedUserMap = array_flip(SyncApp::$config['nameMap']);
        if ($matchOne) {
            $assignee = $issue['assignee'];
            if (isset($assignee['login'])) {
                $assignee = $assignee['login'];
            }
            if (isset($reversedUserMap[$assignee])) {
                return $reversedUserMap[$assignee];
            }
        } else {
            $work = array();
            foreach ($issue['assignees'] as $assignee) {
                if (isset($assignee['login'])) {
                    $assignee = $assignee['login'];
                }
                if (isset($reversedUserMap[$assignee])) {
                    $work[] = $reversedUserMap[$assignee];
                }
            }
            return $work;
        }
        return false;
    }

    /**
     * @TODO
     * @param $issue
     * @return bool
     */
    public function getTaskValuesArrayFromIssue($issue)
    {
        $issue = $this->stripTasksoupUrlFromIssue($issue);
        $regexSimpleComment = '~\*\*Description:\*\*\n(?<description>.*)\n\n\*\*Notes:\*\*\n(?<notes>.*)\n\n\*\*Client:\*\*\s+_(?<client>.*)_\n\*\*Contact:\*\*\s+_(?<contact>.*)_\n\*\*Project:\*\*\s+_(?<project>.*)_\n\*\*Budget:\*\*\s+_(?<budget>.*)_\n\*\*Due:\*\*\s+_(?<due>.*)_\n*~is';
        if (preg_match($regexSimpleComment, $issue['body'], $matches)) {
            return $matches;
        } elseif (stripos($issue['body'], '**Description**') !== false) {
            // It seems there is some sort of formatting, but it doesn't match anything we know.
            return false;
        }
        return array();
    }

    /**
     * Returns an array with the username as key, and the id as value. If some username is not found, it won't be in the
     * returned array.
     *
     * @param array $nickArray filled with usernames.
     * @return array
     */
    public function getUserIds($nickArray)
    {
        $return = array();
        $userBeans = R::find('user', 'nick IN  (' . R::genSlots($nickArray) . ')', $nickArray);
        foreach ($userBeans as $user) {
            $return[$user->nick] = $user->id;
        }
        return $return;
    }

    /**
     * Returns an array with the fields set to be used straight as an issue in the github client api. This also empties
     * the assignee by default, this is a item.
     *
     * This adds a URL to the body to easily go to the task in tasksoup. This is not included in the simplified comment
     * because it is unique to every task, causing us not to be able to find copies between different tasks. (The id of
     * the task is in the URL).
     *
     * @param $taskBean
     * @return array
     */
    public function getIssueFromTask($taskBean)
    {
        return array(
            'title' => $taskBean->name,
            'body' => $this->getSimplifiedComment($taskBean) . $this->getTasksoupUrlMarkdown($taskBean->id),
            'labels' => $this->getIssueLabelsFromTask($taskBean),
            'assignee' => $this->getIssueAssigneesFromTask($taskBean, true),
        );
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
        $labels = array();
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

    /**
     * This will get the hours assigned to a task for the different members of a team, and tries to map them to a github
     * username. If no username is found in the mapping table, the assigned member is silently dropped.
     *
     * @param \RedBeanPHP\OODB $taskBean
     * @param bool $matchOne Set this to true to only return a string with 1 user with the most hours in it.
     * @return array|string
     */
    public function getIssueAssigneesFromTask($taskBean, $matchOne = false)
    {
        $assignees = array();
        $assignee = '';
        $hours = $taskBean->ownWork;
        $highestHours = 0;
        foreach ($hours as $hr) {
            if ($matchOne && $hr->hours > $highestHours) {
                $highestHours = $hr->hours;
                if (isset(SyncApp::$config['nameMap'][$hr->user->nick])) {
                    $assignee = SyncApp::$config['nameMap'][$hr->user->nick];
                }
            } elseif ($hr->hours > 0) {
                if (isset(SyncApp::$config['nameMap'][$hr->user->nick])) {
                    $assignees[] = SyncApp::$config['nameMap'][$hr->user->nick];
                }
            }
        }
        return $matchOne ? $assignee : $assignees;
    }

    /**
     * Returns the url in markdown format that can be added at the end of a simplified comment, that links back to the
     * tasksoup task.
     *
     * @param int|bool $taskId Should be set to a taskId, or when set to false will return a regex string with which you
     * can find the url, and the id.
     * @return string
     */
    public function getTasksoupUrlMarkdown($taskId)
    {
        if ($taskId !== false) {
            $tasksoupUrl = SyncApp::$config['tasksoupUrl'];
            $return = "\n\n[Task on tasksoup]({$tasksoupUrl}?c=edittask&id={$taskId})";
        } else {
            $return = '~\n\n\[Task on tasksoup]\((.*)\?c=edittask&id=(\d+)\)~';
        }
        return $return;
    }

    /**
     * This strips the url from the body of an issue, so that we can nicely hash the issue body back into the same hash
     * we get when we has the simplified comment made out of a tasksoup task.
     * @param array $issue
     * @return array Return the issue with the url stripped from the body.
     */
    public function stripTasksoupUrlFromIssue($issue)
    {
        $issue['body'] = preg_replace($this->getTasksoupUrlMarkdown(false), '', $issue['body']);
        return $issue;
    }

    /**
     * Returns true if the task is deemed too empty, too little fields filled, to be newly created and synced. This
     * prevents duplicates, or wrongly thinking an issue is copied because the hash matches another empty ticket.
     *
     * @param $taskBean
     * @return bool
     */
    public function isEmpty($taskBean)
    {
        if (trim($taskBean->description) == '' && trim($taskBean->notes) == '' && strlen($taskBean->name) < 8) {
            return true;
        }
        return false;
    }
}