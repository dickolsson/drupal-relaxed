<?php

namespace Drupal\Tests\relaxed\Integration;

require_once __DIR__ . '/ReplicationTestBase.php';

/**
 * @group relaxed
 */
class CouchDBReplicatorTest extends ReplicationTestBase {

  /**
   * Test the replication using the CouchDB replicator.
   */
  public function testCouchdbReplicator() {
    // Run CouchDB to Drupal replication.
    $this->couchDbReplicate($this->sourceDb, 'http://replicator:replicator@127.0.0.1:8080/relaxed/live');
    $this->assertAllDocsNumber('http://replicator:replicator@127.0.0.1:8080/relaxed/live/_all_docs', 9);

    // Run Drupal to Drupal replication.
    $this->couchDbReplicate('http://replicator:replicator@127.0.0.1:8080/relaxed/live', 'http://replicator:replicator@127.0.0.1:8081/relaxed/live');
    $this->assertAllDocsNumber('http://replicator:replicator@127.0.0.1:8081/relaxed/live/_all_docs', 9);

    // Run Drupal to CouchDB replication.
    $this->couchDbReplicate('http://replicator:replicator@127.0.0.1:8081/relaxed/live', $this->targetDb);
    $this->assertAllDocsNumber($this->couchdbUrl . '/' . $this->targetDb . '/_all_docs', 9);
  }

}
