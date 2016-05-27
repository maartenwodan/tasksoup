#!/bin/bash
DATABASE="../database/data.sql"
BACKUP="../database/syncgithub.sql.bak"

echo "Will try to remove table in $DATABASE"

if [ -e ${BACKUP} ]
then
    echo "The backup already exists ($BACKUP), exiting..."
    exit 1
fi

echo "Please be careful, deleting this table will desync github completely with tasksoup. Make sure you make a backup"
echo "before proceeding. This script will create a backup of the database here: $BACKUP"
echo "Trying to backup..."

cp ${DATABASE} ${BACKUP}

if [ -e ${BACKUP} ]
then
    echo "The backup created successfully."
    read -p "Do you want to continue (y/n)? " -n 1 -r
    echo "" #empty line for clean prompt
    if [[ $REPLY =~ ^[Yy]$ ]]
    then
        if [ -e ${DATABASE} ] && [ -w ${DATABASE} ]
        then
            echo "Removing github sync table in database..."
            sqlite3 ${DATABASE} < syncgithub_remove.sql
        else
            echo "Database is not write-able or not found."
        fi
    fi
else
    echo "Failed to backup, exiting..."
    exit 2
fi
