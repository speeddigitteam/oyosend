#!/usr/bin/env bash

# Creates the disposable database the test suite runs against (phpunit.xml sets
# DB_DATABASE=oyosend_testing). compose.yaml mounts this file into the MySQL
# container's init directory, so it runs once, when the data volume is created.
# The trailing % also covers the per-process databases of `artisan test --parallel`.

mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS oyosend_testing;
EOSQL

if [ -n "$MYSQL_USER" ]; then
mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    GRANT ALL PRIVILEGES ON \`oyosend\_testing%\`.* TO '$MYSQL_USER'@'%';
EOSQL
fi
