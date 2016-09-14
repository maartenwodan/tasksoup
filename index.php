<?php

/* TASKSOUP */

@include('auth.php'); //add your own authentication
use RedBeanPHP\SimpleModel as SimpleModel;
use StampTemplateEngine\StampTE as StampTE;
ini_set('session.gc_maxlifetime', (3600*24*30)); //<-- SET SESSION TIME HERE
session_start();
require 'lib/rb.php';
require 'lib/StampTE.php';
R::setup( 'sqlite:database/testdata.sql', NULL, NULL, TRUE ); //<-- CONFIGURE YOUR DATABASE HERE

function cut( $str, $len ) {
	if ( strlen( $str ) < ( $len-3 ) ) return $str;
	return substr( $str, 0, ( $len-3 ) ).'...';
}

array_walk_recursive( $_POST, function( &$value ){ $value = trim( $value ); }  );

function go_home() { 
	header('Location: ' . get_path_info());
	exit; 
}

function get_path_info() {
	if (!array_key_exists('PATH_INFO', $_SERVER))	{
		$pos = strpos($_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING']);
		$asd = substr($_SERVER['REQUEST_URI'], 0, $pos - 1);
		return $asd;
	}
	else {
		return trim($_SERVER['PATH_INFO'], '/');
	}
}

function get_param( $get, $def='' ) { 
	return ( isset( $_GET[$get] ) ) ? $_GET[$get] : $def;
}

//---------------------------------- MODELS / VALIDATION ---------------------------------- 
class Model_Period extends SimpleModel
{
	public function update() 
	{
		if ( !preg_match( '/^\d\d\d\d\-\d\d\-\d\d$/', $this->bean->start ) ) $this->bean->start = R::isoDate();
		if ( !preg_match( '/^\d\d\d\d\-\d\d\-\d\d$/', $this->bean->end ) ) $this->bean->end = R::isoDate();
		if ( $this->bean->end < $this->bean->start ) $this->bean->end = $this->bean->start;
	}
}

class Model_User extends SimpleModel
{
	public function update()
	{
		if ( $this->bean->fullname =='') $this->bean->fullname = 'Mr. Nobody';
		if ( $this->bean->nick =='') $this->bean->nick = 'Anonymous';
		if ( !count( $this->bean->sharedTeamList ) ) $this->bean->sharedTeamList[] = R::findOne( 'team' );
	}
}

class Model_Team extends SimpleModel
{
	public function update()
	{
		if ( $this->bean->name == '' ) $this->bean->name = 'Default team';
	}
	
	public function delete()
	{
		if ( R::count( 'team' ) <= 1 ) die( 'You need at least one team!' );
	}
}

class Model_Work extends SimpleModel
{
	public function update()
	{
		$this->bean->hours = max( 0, $this->bean->hours );
	}
}

class Model_Attendance extends SimpleModel
{
	public function update()
	{
		$this->bean->hours = max( 0, $this->bean->hours );
	}
}

class Model_Task extends SimpleModel
{
	public function update()
	{
		if ( !$this->bean->id ) $this->bean->start = R::isoDate();
		if ( $this->bean->done && is_null( $this->bean->end ) ) $this->bean->end = R::isoDate();
		if ( $this->bean->name == '' ) $this->bean->name = 'Unknown task';
		$this->bean->progress = max( 0, min( 100, $this->bean->progress ) );
	}
}

$priorityLabels = array('⏳ t', '-1', '-', '+1', '☢ +2', '⚡ a');
$taskTypeLabels = array('Bug', 'Change', 'Improvement', 'Feature', 'Task');
$taskTypeDescriptions = array(
	'Anything that breaks the application, unexpected behavior, or showstopping errors.',
	'Anything that changes the current behavior of the application, where the old behavior cannot be used anymore.',
	'All things that make a current behavior or method better.',
	'Anything new.',
	'Something like research, brainstorming, server config, anything that takes time but does not involve the application.',
);
$taskTypeColors = array(
	'{"color":"#F7464A", "highlight": "#FF5A5E", "label": "Red"}',
	'{"color":"#FDB45C", "highlight": "#FDB45C", "label": "Yellow"}',
	'{"color":"#949FB1", "highlight": "#A8B3C5", "label": "Grey"}',
	'{"color":"#46BFBD", "highlight": "#5AD3D1", "label": "Green"}',
	'{"color":"#4D5360", "highlight": "#616774", "label": "Dark Grey"}',
);
$cmd            = get_param( 'c', 'main' );
$currentTeamID  = ( isset( $_SESSION['team_id'] ) ) ? $_SESSION['team_id'] : 0;
$currentTeam    = R::load( 'team', $currentTeamID );

if ( !$currentTeam->id ) {
	$currentTeam = R::findOne( 'team' );
	if ( !$currentTeam ) {
		$currentTeam = R::dispense( 'team' ); 
		R::store( $currentTeam );
	}
	$_SESSION['team_id'] = $currentTeam->id;
}

$pID = ( isset( $_SESSION['period_id'] ) ) ? $_SESSION['period_id'] : 0;
$currentPeriod = R::load( 'period', $pID );
if ($currentPeriod->id) {
	$currentYear = date( 'Y', strtotime( $currentPeriod->start ) );
} else {
	$currentYear = date( 'Y' );
}

if ( $currentTeam->id && ( $cmd === 'selectyear' || !$currentPeriod->id ) ) {
	$year = get_param( 'year', date( 'Y' ) ).'-12-31';
	$yearBegin = get_param( 'year', date('Y') ).'-01-01';
	$justAperiod = reset( $currentTeam->withCondition(' `end` <= ? AND end >= ? ORDER BY (CASE WHEN `start` <= ? THEN 1 ELSE 0 END) DESC, `start` DESC LIMIT 1',array( $year, $yearBegin, R::isoDate() ) )->ownPeriodList );
	if ($justAperiod || $cmd === 'selectyear') {
		$_SESSION['period_id'] = $justAperiod->id;
		$_SESSION['team_id'] = $justAperiod->team_id;
		go_home();
	}
}

$efficiency   = R::getCell( 'SELECT AVG(efficiency) FROM ( SELECT * FROM period WHERE closed = ? AND team_id = ? ORDER BY `end` DESC LIMIT 25 ) ', array( TRUE, $currentTeam->id ) );
if ($efficiency == 0) $efficiency = 0.7;
$availableHrs = R::getCell( 'SELECT SUM(hours) FROM attendance WHERE period_id = ? ', array( $currentPeriod->id ) );
$realAvailHrs = $availableHrs * $efficiency;

//---------------------------------- ACTIONS (POST) ---------------------------------- 
switch( $cmd ) {
	case 'selectperiod':
		$_SESSION['period_id'] = get_param( 'period' ); go_home();
		break;

	case 'selectteam':
		$_SESSION['team_id'] = get_param( 'id' );
		$newTeam = R::load( 'team', get_param( 'id' ) );

		if ($newTeam->id) {
			$samePeriod = reset(
				$newTeam
					->withCondition(
					' end >= ? ORDER BY start ASC LIMIT 1 ', array( $currentPeriod->start ) )
					->ownPeriodList
			);
			if ($samePeriod->id) {
				$_SESSION['period_id'] = $samePeriod->id;
			} else {
				unset($_SESSION['period_id']);
			}
		}
		
		go_home();
		break;
	
	case 'delete':
		R::trash( R::load( get_param('type'), get_param('id') ) ); go_home();
		break;

	case 'saveuser':
		$user = R::load( 'user', get_param( 'id' ) );
		$user->import( $_POST, 'fullname,nick,email,phone,photo,team_id' );
		$user->sharedTeamList = R::batch('team', ( isset($_POST['teams'] ) ) ? $_POST['teams'] : array() );
		R::transaction( function() use( &$user ) { R::store( $user ); } );
		go_home();
		break;
		
	case 'saveperiod':
		$period = R::load( 'period', get_param( 'id' ) );
		
		$teamHasChanged = ( $period->team_id != $_POST['team_id'] && $period->id );
		$period->import( $_POST, 'start,end,closed,team_id' );
		
		$total = $done = 0;
		if ( $period->closed ) {
			foreach( $period->ownTaskList as $task ) {
				foreach( $task->xownWorkList as $work ) {
					$total += $work->hours;
					if ( $task->done ) $done  += $work->hours;
				}
			}
			$period->efficiency = ( $done / max( 1, $total ) );
		}
	
		if ( $teamHasChanged ) {
			$period->xownAttendanceList = array();
		} elseif ( isset( $_POST['attendance'] ) ) {
			$attendanceList = array();
			foreach( $_POST['attendance'] as $userID => $hours ) {
				$attendanceList[] = R::dispense('attendance')
					->import( array( 'user_id' => $userID, 'hours' => $hours ) );
			}
			$period->xownAttendanceList = $attendanceList;
		}
		R::transaction( function() use( &$period ) { R::store( $period ); } );
		go_home();
		break;
	
	case 'savetask':
		$task = R::load( 'task', get_param( 'id' ) );
		$task->import( $_POST, 
			'name,client,contact,project,budget,notes,description,due,type,prio,team_id,progress,done,period_id');
		
		$work = array();
		foreach( $_POST['work'] as $userID => $hours ) {
			$work[] = R::dispense('work')
				->import( array( 'hours'=>$hours, 'user_id'=>$userID ) );
		}
		$task->xownWorkList = $work;

		R::transaction( function() use ( &$task ){ R::store( $task ); } );
		go_home();
		break;
	
	case 'editwork':
		if (!isset( $_POST['tasks'] ) ) go_home();
		R::transaction( function() use( &$currentPeriod, &$currentTeam ) {
			$nextPeriod = FALSE;

			if ( $currentPeriod ) {
				$nextPeriod = reset( $currentTeam
					->withCondition( 'start>=? ORDER BY start ASC LIMIT 1', array( $currentPeriod->end ) )
					->ownPeriodList
				);
				$prevPeriod = reset( $currentTeam
					->withCondition( 'end<=? ORDER BY end DESC LIMIT 1', array( $currentPeriod->start ) )
					->ownPeriodList
				);
			}

			foreach( R::batch( 'task',$_POST['tasks']) as $task )
				if ( $_POST['operation'] === 'next' && $nextPeriod )
					R::store( $task->setAttr( 'period', $nextPeriod ) );
				elseif ( $_POST['operation'] === 'prev' && $prevPeriod )
					R::store( $task->setAttr( 'period', $prevPeriod ) );
				elseif ( $_POST['operation'] === 'nextcopy' && $nextPeriod )
					R::store( $task->setAttr('id', 0)->setAttr( 'period', $nextPeriod ) );
				elseif ( $_POST['operation'] === 'done' )
					R::store( $task->import( array( 'done' => 1, 'progress' => 100 ) ) );
				elseif ( $_POST['operation'] === 'notdone' )
					R::store( $task->import( array( 'done' => 0, 'progress' => 0 ) ) );
		} );
		go_home();
		break;
	
	case 'saveteam':
		$team = R::load( 'team', get_param( 'id' ) )->import($_POST, 'name,description');
		R::store( $team ); go_home();
		break;
}

//---------------------------------- VIEWS (GET) ---------------------------------- 
$template = StampTE::load('template.html');
switch( $cmd ) {
	case 'main':
		$toolbar = $template->getToolbar();
		$years = R::getCol('SELECT DISTINCT strftime("%Y",start) FROM period ORDER BY start ASC');
		if ( count( $years ) > 0 ) {
			$yearSelector = $toolbar->getYearSelector();
			foreach( $years as $year ) {
				$yearSelector->add(
					$yearSelector->getYearOption()
						->setYear( $year )
						->attr( 'selected', ( $currentYear == $year ) )
				);
			}
			$toolbar->add( $yearSelector );
		}
		$template->add($toolbar);

		$sidebar = $template->getSidebar();

		//Teams
		foreach(R::find('team') as $team) {
			$teamRow = $sidebar->getTeam();
			$availableHrsTeam = 0;
			$teamRow->setTeamDisplayName( cut( $team->name, 20 ) )
				->setLinkToTeamView("?c=selectteam&id={$team->id}")
				->setSelected(($team->id == $currentTeam->id) ? 'selected' : '');
			$sidebar->add($teamRow);
		}

		//Team members
		foreach($currentTeam->sharedUserList as $user) {
			$booked    = R::getCell('SELECT SUM(hours) FROM work 
			LEFT JOIN task ON task.id = work.task_id
			WHERE user_id = ? AND period_id = ? ', array($user->id, $currentPeriod->id));
			$available = R::getCell('SELECT SUM(hours) FROM attendance WHERE user_id = ? AND period_id = ? ', array($user->id, $currentPeriod->id));
			if ( is_null( $available ) ) $available = 0;
			if ( is_null( $booked ) ) $booked = 0;
			
			$sidebar->add(
				$sidebar->getUser()
					->setUserDisplayName( cut( $user->nick, 20 ) )
					->setLinkToUserEditor("?c=edituser&id={$user->id}")
					->setBooked($booked)->setAvailable($available)
					->setWorkLoadClassification( ($booked > $available) ? 'red' : 'normal' ) );
		}

		//Periods
		$periods = $currentTeam
			->withCondition('
				CAST( strftime("%Y", start) AS INTEGER ) = ? 
				ORDER BY start DESC', array($currentYear))
			->ownPeriodList;
		foreach($periods as $period) {
			$startTime  = strtotime( $period->start );
			$endTime    = strtotime( $period->end );
			$periodRow  = $sidebar->getPeriod()
				->setPeriodDisplayName( date('W | D d M', $startTime ). ' - '.date('D d M', $endTime))
				->setStatus(($period->closed) ? 'closed' : 'open')->setEff( number_format( $period->efficiency , 1 ) )
				->setLinkToPeriodView("?c=selectperiod&period={$period->id}");
			if ($currentPeriod->id == $period->id) $periodRow->setSelected('selected');
			$sidebar->add($periodRow);
		}

		$sidebar->setYear($currentYear);
		$template->add($sidebar);

		//Tasks
		$taskList = $template->getTaskList();
		$workload = $total = 0;
		$tasks = $currentPeriod->with(' ORDER BY 
			(CASE WHEN `done` = 1 THEN 1 ELSE 0 END) ASC, `prio` DESC, 
			(CASE WHEN `due` = "" THEN "9999-99-99" ELSE `due` END) ASC ')
			->ownTaskList;
		
		if (!count($tasks)) {
			$template->add(
				$template->getNoTasksYet()
					->setLinkToPeriodEditor("?c=editperiod&id={$currentPeriod->id}")
					->setLinkToAddTask('?c=edittask&id=0')
					->setLinkToTeamEditor("?c=editteam&id={$currentTeam->id}"));
			break;
		}

		foreach($tasks as $task) {
			$taskRow = $taskList->getTask();
			$taskRow->setId($task->id);
			$weight = $ppl = 0;
			$nicks = array();
			foreach( $task->xownWorkList as $work ) {
				$weight += $work->hours;
				if (!$work->hours) continue;
				$photo = $taskRow->getPhoto()
					->setSourceURL($work->user->photo)
					->setTooltip($work->user->nick)
					->setDescription($work->user->nick);
				if (++$ppl < 4) $taskRow->add($photo);
				$nicks[] = $work->user->nick;
			}
			$taskRow->setNicks('|' . implode('|', $nicks) . '|');
			if (!$task->done) $workload += $weight;
			$total += $weight;
			$warning = (!$task->done && $task->due != '' && strtotime($task->due)<time()-(3600*10));
			$percentage = $task->progress;
			$taskRow->setStart($task->start)
				->setEnd($task->end)
				->setHours($weight)
				->setHoursLeftInPeriod(floor($realAvailHrs - $total))
				->setProgressColor(($warning) ? 'red' : 'normal')
				->setProgressText(($task->due=='') ? $percentage.' %' : $task->due)
				->setProgressBarWidth($percentage)
				->setTaskTypeLevel($task->type)
				->setTaskTypeLabel($taskTypeLabels[$task->type])
				->setTaskTypeColor($taskTypeColors[$task->type])
				->setTaskTypeDescription($taskTypeDescriptions[$task->type])
				->setPriorityLabel($priorityLabels[$task->prio])
				->setPriorityLevel($task->prio)
				->setPriorityLabel($priorityLabels[$task->prio])
				->setStatus(($task->done) ? 'done' : ( $total > $realAvailHrs ? 'red' : 'todo'))
				->setLinkToTaskEditor("?c=edittask&id={$task->id}")
				->setTooltip( $task->description )
				->setClient( cut( $task->client, 20 ) )->setContact( cut( $task->contact, 80 ) )
				->setName( cut( $task->name, 80 ) );

            $syncGitHub = R::findOne( 'syncgithub', ' task_id = ? ', array( $task->id ) );
            if ($syncGitHub->id) {
                if (!file_exists('syncgithub' . DIRECTORY_SEPARATOR . 'config.php')) {
                    throw new Exception ('SyncGitHub config.php does not exist, even though sync table does exist. Please create a config or uninstall SyncGitHub.');
                }
                $syncGitHubConfig = include_once('syncgithub' . DIRECTORY_SEPARATOR . 'config.php');
                $syncGitHub = $taskRow->getSyncGitHubRow()
                    ->setGitHubLink('https://github.com/' . $syncGitHubConfig['gitLocation'] . '/' . $syncGitHubConfig['gitRepo'] . '/issues/' . $syncGitHub->issue_id)
                    ->setGitHubIssueId($syncGitHub->issue_id);
                $taskRow->add($syncGitHub);
            }

			$taskList->add($taskRow);
		}
		
		$percentageDone = (round((($total-$workload)/(max(1,$total)))*100));
		$stillTodoHours = ($total-$workload);
		$displayEfficiency = number_format($efficiency,1);
		$displayRealAvailableHours = round($realAvailHrs);
		$information = "<b title=\"Hours still to do.\">{$stillTodoHours}</b>/<span title=\"Total hours booked.\">{$total}<span>
		 (".count($tasks)." tasks) - {$percentageDone}% done @ 
		 <span title=\"Average efficiency (done/total) over all periods for this team.\">{$displayEfficiency}</span> 
		 efficiency (max workload: {$displayRealAvailableHours}/".(intval($availableHrs))." hrs. )";
		if ( count($tasks) ) $taskList->add( $taskList->getOperations() );

		$taskList->setFrom($currentPeriod->start)
			->setTil($currentPeriod->end)
			->injectRaw('information',$information)
			->setTotalHours($total)
			->setLinkToTeamEditor("?c=editteam&id={$currentTeam->id}");
			
		if ($currentPeriod->id) $taskList->add( 
			$taskList->getPeriodButtons()
				->setLinkToPeriodEditor("?c=editperiod&id={$currentPeriod->id}")
				->setLinkToAddTask('?c=edittask&id=0'));
		
		$template->add($taskList->setClosed($currentPeriod->closed ? 'closed' : 'open')->setLink("http://{$_SERVER['HTTP_HOST']}".get_path_info()."/?c=selectperiod&period={$currentPeriod->id}"));
		break;

	case 'editperiod':
		$period = R::load('period', get_param('id')); 
		if (!$period->id) $period->import( array( 'start' => R::isoDate(), 'end' => R::isoDate() ) );
		if (!$period->teamID) $period->teamID = $currentTeam->id;

		$periodEditor = $template->getPeriodEditor()
			->setActionURL("?c=saveperiod&id={$period->id}")
			->setStart($period->start)
			->setEnd($period->end)
			->injectAttr('checked'.(($period->closed) ? 'Closed' : 'Open'), 'checked');

		foreach(R::find('team', 'ORDER BY `name` ASC') as $team) {
			$teamOption = $periodEditor->getTeamOption();
			$teamOption->setName($team->name)->setTeamID($team->id)->attr('selected', ($team->id == $period->teamID));
			$periodEditor->add($teamOption);
		}

		$attendanceList = $period->xownAttendanceList;
		foreach($attendanceList as $attends) $userAttendance[$attends->user_id] = $attends;
		foreach($period->team->with('ORDER BY `nick` ASC')->sharedUserList as $teamMember) {
			$periodEditor->add(
				$periodEditor->getAttendance()
					->setUserID($teamMember->id)
					->setHoursAvailable( isset( $userAttendance[$teamMember->id] ) ? $userAttendance[$teamMember->id]->hours : 0 )
					->setName($teamMember->nick)
			);
		}

		if ($period->id) $periodEditor->add(
			$periodEditor->getDeleteButton()
				->setLinkToDeletePeriod("?c=delete&type=period&id={$period->id}") );

		$template->add($periodEditor);
		break;

	case 'edituser':
		$user = R::load('user',get_param('id'));
		$userEditor = $template->getUserEditor()
			->setActionURL("?c=saveuser&id={$user->id}")
			->setFullname($user->fullname)
			->setNick($user->nick)
			->setEmail($user->email)
			->setPhone($user->phone)
			->setPhoto($user->photo);

		foreach(R::find('team') as $team) {
			$teamOption = $userEditor->getTeamOption();
			$inTeam = (in_array($team->id, array_keys($user->sharedTeamList)));
			$teamOption->setName($team->name)->setTeamID($team->id)->attr('selected',$inTeam);
			$userEditor->add($teamOption);
		}

		if ($user->id) {
			$userEditor->add( $userEditor->getDeleteButton()
					->setLinkToDeleteUser("?c=delete&type=user&id={$user->id}")
			);
		}

		$template->add($userEditor);
		break;

	case 'editteam':
		$team = R::load( 'team', get_param('id') );
		$teamEditor = $template->getTeamEditor()
			->setActionURL("?c=saveteam&id={$team->id}")
			->setName($team->name)
			->setDescription($team->description);

		if ($team->id) $teamEditor->add(
			$teamEditor->getDeleteButton()
				->setLinkToDeleteTeam("?c=delete&type=team&id={$team->id}") );

		$template->add($teamEditor);
		break;

	case 'edittask':
		$task = R::load( 'task', get_param('id') );
		if (!$task->id) $task->import( array('period' => $currentPeriod, 'progress' => 0, 'prio' => 2 ) );

		$taskEditor = $template->getTaskEditor()
			->setActionURL("?c=savetask&id={$task->id}")
			->setName($task->name)
			->setTaskID($task->id)
			->setClient($task->client)
			->setContact($task->contact)
			->setProject($task->project)
			->setBudget($task->budget)
			->setDue($task->due)
			->setProgress($task->progress)
			->setDescription($task->description)
			->setNotes($task->notes);

		foreach($taskTypeLabels as $taskTypeLevel => $taskTypeLabel) {
			$typeOption = $taskEditor->getTypeOption();
			$typeOption->setTaskTypeLevel($taskTypeLevel)->setTaskTypeLabel($taskTypeLabel);
			$typeOption->attr('selected', ($task->type == $taskTypeLevel));
			$taskEditor->add($typeOption);
		}

		foreach($priorityLabels as $priorityLevel => $priorityLabel) {
			$priorityOption = $taskEditor->getPriorityOption();
			$priorityOption->setPriorityLevel($priorityLevel)->setPriorityLabel($priorityLabel);
			$priorityOption->attr('selected', ($task->prio == $priorityLevel));
			$taskEditor->add($priorityOption);
		}
		
		foreach( R::find( 'period', ' ORDER BY start DESC LIMIT 12' ) as $period) {
			$periodOption = $taskEditor->getPeriodOption()
				->setFullPeriod( date( 'l d F Y', strtotime( $period->start ) ).' - '.date( 'l d F Y', strtotime( $period->end ) ) )
				->setPeriodID($period->id)
				->setPeriodLabel( date( 'd M', strtotime( $period->start ) ) . ' - ' . date( 'd M',  strtotime( $period->end ) ) . " ({$period->team->name})" );
			if ( $period->id == $task->period->id ) $periodOption->setSelected( 'selected' );
			$taskEditor->add($periodOption);
		}

		$syncGitHub = R::findOne( 'syncgithub', ' task_id = ? ', array( get_param('id') ) );
		if ($syncGitHub->id) {
			if (!file_exists('syncgithub' . DIRECTORY_SEPARATOR . 'config.php')) {
				throw new Exception ('SyncGitHub config.php does not exist, even though sync table does exist. Please create a config or uninstall SyncGitHub.');
			}
			$syncGitHubConfig = include_once('syncgithub' . DIRECTORY_SEPARATOR . 'config.php');
			$syncGitHub = $taskEditor->getSyncGitHub()
				->setGitHubLink('https://github.com/' . $syncGitHubConfig['gitLocation'] . '/' . $syncGitHubConfig['gitRepo'] . '/issues/' . $syncGitHub->issue_id)
				->setGitHubIssueId($syncGitHub->issue_id);
			$taskEditor->add($syncGitHub);
		}
		
		$workList = $task->xownWorkList;
		foreach($workList as $work) $workList[$work->user_id] = $work;
		foreach($task->period->team->sharedUserList as $user) {
			$value = (isset($workList[$user->id])) ? $workList[$user->id]->hours : 0;
			$resource = $taskEditor->getResource()
				->setUserID($user->id)
				->setHours($value)
				->setNick($user->nick);
			$taskEditor->add($resource);
		}
		if ($task->id) $taskEditor->add( 
			$taskEditor->getDeleteButton()->setLinkToDeleteTask("?c=delete&type=task&id={$task->id}") );
		$template->add($taskEditor);
		break;
}
echo $template;
