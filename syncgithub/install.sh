#!/bin/bash
DATABASE="../database/data.sql"

echo "Will try to setup table in $DATABASE"

if [ -e ${DATABASE} ] && [ -w ${DATABASE} ]
then
    echo "Creating github sync table in database..."
    sqlite3 ${DATABASE} < syncgithub_install.sql
else
    echo "Database is not write-able or not found."
fi
