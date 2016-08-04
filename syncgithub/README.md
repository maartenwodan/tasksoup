Sync GitHub
===========

Features
========

 - Sync assigned to back and forth, only with username mapping.

Install
=======
Setup your database name in the following files:

 - install.sh 
 - remove.sh
 - config.php

Run `composer install` to install the github library api.

Then after that, run `install.sh` to create the required tables, and 
setup cron to run the `run.php` file. You can easily setup a logger as 
well as shown in the 15 minute example below:

    15 * * * * /tasksoup/syncgithub/run.php 2>&1 | /usr/bin/logger -t tasksoupSyncGitHub

Uninstall
=========

*Remove your cron*, no understatement to that, otherwise the script will
keep on running. 

If you want to clear your tasksoup database of the sync table as well
you can run `remove.sh`. This will create a backup as well, but make
**sure** you do not run the github sync after that, because with an 
empty database it might just create all tickets new again.

Future
======

 - Sync priority with github prio's.
 - Sync usernames, contact can be @username if a map exists. Requires
 username mapping.
 - Sync Progress with whatever (zenhub?).
 - Connect with GitHooks instead of cron?

Inner workings
==============

Prequisites
-----------
 - Tasksoup query OPEN tasks every x amount of time (setup in cron).
 - Query github <-> tasksoup relation table. (ticket number to ticket 
number)

Tasksoup (master) -> Github
---------------------------

Creating:

 - Create any tickets not existing in relation table.
   - Future tasksoup related 'copied' items should be recognized?
   - The title is the task name.
   - Description + notes will be the opening comment.
   - Client, Contact, Project, Budget, and Due will be left in the 
   opening comment if set.
   - Add link to the tasksoup item.
 - Label them 'tasksoup' (option).
 - Label them with the task type (option, requires mapping of tasksoup
 type to github label).
 
Editing:

 - Tickets that are in the open tasks query AND in the relationship
 table and are edited (checked by hash) will be updated on github.
 - If the tickets does not exist anymore on github, it will be created.
 - Just the top comment will be updated with any new text and client,
 contact,project and budget. 
   - The same way the created comment is build up.
 - Remove all tags. (option).
 - Add tag tasksoup (option).
 - Add tag task type (option).
 
Closing:

 - Any tickets that are open, and open in the sync, and in a closed 
 period, those will be closed.
 - Close any tickets on github IN the relationship table but NOT in the
 open tasks query.
 - Leave comment on github ticket stating the date it was closed in
 tasksoup. Or if it was deleted.

Deleting (optional):

 - Query for any tickets in relationship table that do not exist 
 anymore.
 - Delete those tickets on github.
 - If succesful, delete them in the relationship table.

Tasksoup (master-sync) <-> Github
---------------------------------

Requires more data, since tasksoup doesn't keep a modified table. We 
will generate a first github comment, but not sync or save it yet, and 
we will make a hash from it. This hash is saved together with the 
relation table. Now we can take the following steps to assure mutual
sync in the different processes.

Creating from tasksoup:

 - Same steps as tasksoup master, except one crucial step before we 
 create a ticket.
 - Before we create a ticket that doesn't exist in the relation table we
 will do a check if the hash exists, because tasksoup workflow is also
 to copy tickets. If the hash exists, and the ticket the hash belongs to
 has ended today or yesterday, then we assume it is a copy of that
 ticket and update that entry in the relation table so it points to the
 newly copied tasksoup ticket. Create a new item in the sync table with
 an empty github issue_id so we do not create the old item again, even
 if it is opened again.
 
Updating from tasksoup:

 - The same as editing in one way sync, since tasksoup remains the
 master in this sync.
 
Closing from tasksoup:

 - Also the same as in master - slave.
 
Creating from github:

 - Check if the ticket already exists in the relationship table, 
 otherwise create it in tasksoup.
 
Updating from github:

 - Compare the hash of the first comment to the hash in the relationship
 table, if it has changed we can update / extra the first comment in
 github. We need to extract the data from the github comment. 
 - After extracting the data as good as we can, this can be a little
 clumsy but we will log errors, we update the ticket.
 
Closing from github:

 - Get all open tickets in tasksoup from github.
 - Check if they are closed.
 - Set percentage to 100% in tasksoup.
 - Set done in tasksoup.
 
