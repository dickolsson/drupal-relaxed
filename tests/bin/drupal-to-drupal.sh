#!/bin/sh

set -ev

mv $TRAVIS_BUILD_DIR/../drupal/core/modules/system/tests/modules/entity_test $TRAVIS_BUILD_DIR/../drupal/modules/entity_test
mv $TRAVIS_BUILD_DIR/../drupal/modules/relaxed/tests/modules/relaxed_test $TRAVIS_BUILD_DIR/../drupal/modules/relaxed_test

# Enable dependencies for the first drupal instance.
drush -l http://drupal.loc en --yes entity_test, relaxed_test || true
drush -l http://drupal2.loc en --yes entity_test, relaxed_test || true

# Load documents from documents.txt and save them in the 'source' database.
while read document
do
  curl -X POST \
       -H "Content-Type: application/json" \
       -d "$document" \
       admin:admin@drupal.loc/relaxed/default;
done < $TRAVIS_BUILD_DIR/tests/fixtures/documents.txt

drush cr

# Get all docs from couchdb db.
curl -X GET http://admin:admin@drupal.loc/relaxed/default/_all_docs

# Run the replication.
nohup curl -X POST -H "Accept: application/json" -H "Content-Type: application/json" -d '{"source": "http://admin:admin@drupal.loc/relaxed/default", "target": "http://admin:admin@drupal2.loc/relaxed/default", "worker_processes": 1}' http://localhost:5984/_replicate &
sleep 60

curl -X GET http://admin:admin@drupal2.loc/relaxed/default/_all_docs | tee /tmp/all_docs.txt

#-----------------------------------
sudo cat /var/log/couchdb/couch.log
#-----------------------------------
sudo cat /var/log/apache2/error.log
#-----------------------------------


COUNT=$(wc -l < $TRAVIS_BUILD_DIR/tests/fixtures/documents.txt)
USERS=4
COUNT=$(($COUNT + $USERS));
test 1 -eq $(egrep -c "(\"total_rows\"\:$COUNT)" /tmp/all_docs.txt)
