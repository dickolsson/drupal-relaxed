#!/bin/sh

set -ev

# Install mocha-phantomjs, pouchdb and other dependences.
npm install -g mocha-phantomjs
npm install chai
npm install es5-shim
npm install mocha
npm install pouchdb@"~4.0"

mv $TRAVIS_BUILD_DIR/../drupal/core/modules/system/tests/modules/entity_test $TRAVIS_BUILD_DIR/../drupal/modules/entity_test
mv $TRAVIS_BUILD_DIR/../drupal/modules/relaxed/tests/pouchdb/test.html $TRAVIS_BUILD_DIR/../drupal/test.html
mv $TRAVIS_BUILD_DIR/../drupal/modules/relaxed/tests/pouchdb/test.js $TRAVIS_BUILD_DIR/../drupal/test.js
mv $TRAVIS_BUILD_DIR/../drupal/modules/relaxed/tests/modules/relaxed_test $TRAVIS_BUILD_DIR/../drupal/modules/relaxed_test

# Enable dependencies.
drush en --yes entity_test, relaxed_test || true

mocha-phantomjs -s localToRemoteUrlAccessEnabled=true -s webSecurityEnabled=false test.html | tee /tmp/output.txt

#-----------------------------------
sudo cat /var/log/apache2/error.log
#-----------------------------------

test 1 -eq $(egrep -c "(2 passing)" /tmp/output.txt)
