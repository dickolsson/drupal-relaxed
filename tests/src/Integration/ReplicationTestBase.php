<?php

namespace Drupal\Tests\relaxed\Integration;

use Drupal\KernelTests\KernelTestBase;
use Drupal\workspaces\Entity\Workspace;
use Doctrine\CouchDB\CouchDBClient;
use Relaxed\Replicator\ReplicationTask;
use Relaxed\Replicator\Replicator;

class ReplicationTestBase extends KernelTestBase {

  protected $strictConfigSchema = FALSE;

  /**
   * CouchDB port.
   *
   * @var int
   */
  protected $port;

  /**
   * CouchDB source database name.
   *
   * @var string
   */
  protected $sourceDb;

  /**
   * CouchDB target database name.
   *
   * @var string
   */
  protected $targetDb;

  /**
   * CouchDB url.
   *
   * @var string
   */
  protected $couchdbUrl;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'serialization',
    'system',
    'key_value',
    'multiversion',
    'relaxed',
    'workspaces',
    'entity_test',
    'relaxed_test',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->installConfig(['multiversion', 'workspaces', 'relaxed', 'relaxed_test']);
    $this->installEntitySchema('workspace');
    $this->installEntitySchema('user');
    Workspace::create(['id' => 'live', 'label' => 'Live'])->save();

    $this->sourceDb = 'source';
    $this->targetDb = 'target';
    $this->port = getenv('COUCH_PORT') ?: 5984;
    $this->couchdbUrl = 'http://localhost:' . $this->port;

    // If source database exists, delete it.
    if ($this->existsDb($this->sourceDb)) {
      $this->deleteDb($this->sourceDb);
    }

    // If target database exists, delete it.
    if ($this->existsDb($this->targetDb)) {
      $this->deleteDb($this->targetDb);
    }

    // Create a source database.
    $response_code = $this->createDb($this->sourceDb);
    $this->assertEquals(201, $response_code);

    // Create a target database.
    $response_code = $this->createDb($this->targetDb);
    $this->assertEquals(201, $response_code);

    // Load documents from documents.txt and save them in the 'source' database.
    $handle = fopen(realpath(dirname(__FILE__) . '/../..') . '/fixtures/documents.txt', "r");
    if ($handle) {
      $curl = curl_init();
      while (($line = fgets($handle)) !== FALSE) {
        curl_setopt_array($curl, [
          CURLOPT_HTTPGET => FALSE,
          CURLOPT_POST => TRUE,
          CURLOPT_POSTFIELDS => $line,
          CURLOPT_URL => "$this->couchdbUrl/$this->sourceDb",
          CURLOPT_NOBODY => FALSE,
          CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
          ],
        ]);

        curl_exec($curl);
      }

      fclose($handle);
      curl_close($curl);
    } else {
      $this->fail("Error when reading documents.txt");
    }
  }

  /**
   * Check if a database exists.
   */
  protected function existsDb($db_name) {
    $curl = curl_init();
    curl_setopt_array($curl, [
      CURLOPT_NOBODY => TRUE,
      CURLOPT_URL => "$this->couchdbUrl/$db_name",
    ]);

    curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return $code == 200;
  }

  /**
   * Creates a new database.
   */
  protected function createDb($db_name) {
    $curl = curl_init();
    curl_setopt_array($curl, [
      CURLOPT_HTTPGET => FALSE,
      CURLOPT_CUSTOMREQUEST => 'PUT',
      CURLOPT_URL => "$this->couchdbUrl/$db_name",
    ]);

    curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return $code;
  }

  /**
   * Creates delete a database.
   */
  protected function deleteDb($db_name) {
    $curl = curl_init();
    curl_setopt_array($curl, [
      CURLOPT_HTTPGET => FALSE,
      CURLOPT_CUSTOMREQUEST => 'DELETE',
      CURLOPT_URL => "$this->couchdbUrl/$db_name",
      CURLOPT_RETURNTRANSFER => TRUE,
    ]);

    $response = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $this->assertEquals(200, $code);
    if (strpos($response, '{"ok":true}') === FALSE) {
      $this->assertTrue(FALSE, "Error: $response");
    }
    curl_close($curl);

    return $code;
  }

  /**
   * Replicates content from source and target using the CouchDB replicator.
   */
  protected function couchDbReplicate($source, $target) {
    $curl = curl_init();
    curl_setopt_array($curl, [
      CURLOPT_HTTPGET => FALSE,
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => '{"source": "' . $source . '", "target": "' . $target . '", "http_connections":2, "worker_processes":1}',
      CURLOPT_URL => "$this->couchdbUrl/_replicate",
      CURLOPT_NOBODY => FALSE,
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
      ],
      CURLOPT_RETURNTRANSFER => TRUE,
    ]);
    $response = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    switch ($code) {
      case 200:
        $this->assertTrue(TRUE, 'Replication request successfully completed.');
        break;
      case 202:
        $this->assertTrue(TRUE, 'Continuous replication request has been accepted.');
        break;
      case 400:
        $this->assertTrue(FALSE, 'Invalid JSON data.');
        break;
      case 401:
        $this->assertTrue(FALSE, 'CouchDB Server Administrator privileges required.');
        break;
      case 404:
        $this->assertTrue(FALSE, 'Either the source or target DB is not found or attempt to cancel unknown replication task.');
        break;
      case 500:
        $this->assertTrue(FALSE, "Server error: $response");
        break;
      default:
        $this->assertTrue(FALSE, "Error: $code");
    }
    curl_close($curl);

    return $code;
  }

  /**
   * Replicates content from source to target using the PHP replicator.
   */
  protected function phpReplicate($data) {
    $json = json_decode($data, true);
    if (json_last_error() != JSON_ERROR_NONE) {
      throw new Exception('Invalid JSON.');
    }

    $source = CouchDBClient::create($json['source']);
    $target = CouchDBClient::create($json['target']);

    $task = new ReplicationTask(null, false, null, null, false, null, 10000, 10000, false, "all_docs", 0, 2, 2);
    $replicator = new Replicator($source, $target, $task);

    return $replicator->startReplication();
  }

  /**
   * Replicates content from source and target using the /_replicate endpoint.
   */
  protected function endpointReplicate($data, $endpoint) {
    $curl = curl_init();
    curl_setopt_array($curl, [
      CURLOPT_HTTPGET => FALSE,
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => $data,
      CURLOPT_URL => $endpoint,
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
      ],
      CURLOPT_RETURNTRANSFER => TRUE,
    ]);
    $response = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $this->assertEquals(200, $code);
    if (strpos($response, 'error') !== FALSE) {
      $this->assertTrue(FALSE, "Replication error: $response");
    }
    curl_close($curl);

    return $response;
  }

  /**
   * Assert that the database contain the correct number of docs.
   *
   * @param $db_url
   * @param $docs_number
   */
  public function assertAllDocsNumber($db_url, $docs_number) {
    $all_docs = $this->getAllDocs($db_url);
    preg_match('~"total_rows":([/\d+/]*)~', $all_docs, $output);
    $this->assertEquals($docs_number, $output[1], 'The request returned the correct number of docs.');
  }

  /**
   * Getsl all docs from a database.
   *
   * @param $db_url
   * @return mixed
   */
  protected function getAllDocs($db_url) {
    $curl = curl_init();
    curl_setopt_array($curl, [
      CURLOPT_HTTPGET => TRUE,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_URL => $db_url,
    ]);
    $response = curl_exec($curl);
    curl_close($curl);
    return $response;
  }

}
