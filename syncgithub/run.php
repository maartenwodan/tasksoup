#!/usr/bin/php
<?php
/**
 * This script can be run every x amount of time to sync the issues between github and tasksoup. Configuration can be
 * done in the config.php file. An example is included. This just starts the SyncApp class, and loads the required files
 * for the application.
 *
 * Date: 27-5-16
 */

require_once 'vendor/autoload.php';
require_once '../lib/rb.php';
require_once('sync_app.php');
require_once('syncgithub_model.php');

$sync = new SyncApp();
$sync->run();