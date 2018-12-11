<?php

namespace Drupal\Tests\relaxed\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\relaxed\HttpMultipart\Message\MultipartResponse;
use GuzzleHttp\Psr7;

/**
 * Tests the /db/doc resource.
 *
 * @group relaxed
 * @todo {@link https://www.drupal.org/node/2600490 Test more entity types.}
 */
class DocResourceTest extends ResourceTestBase {

  public function testHead() {
    // HEAD and GET is handled by the same resource.
    $entity_types = ['entity_test_rev'];
    foreach ($entity_types as $entity_type) {
      // Create a user with the correct permissions.
      $permissions = $this->entityPermissions($entity_type, 'view');
      $permissions[] = 'administer workspaces';
      $permissions[] = 'perform pull replication';
      $account = $this->drupalCreateUser($permissions);
      $this->drupalLogin($account);

      // We set this here just for testing.
      \Drupal::service('workspaces.manager')->setActiveWorkspace($this->workspace);

      $response = $this->httpRequest("$this->dbname/bogus", 'HEAD', NULL);
      $this->assertEquals('404', $response->getStatusCode(), 'HTTP response code is correct for non-existing entities.');

      $storage = $this->entityTypeManager->getStorage($entity_type)->useWorkspace($this->workspace->id());
      $entity = $storage->create();
      $entity->save();
      $entity = $storage->load($entity->id());
      $first_rev = $entity->_rev->value;

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'HEAD', NULL);
      $this->assertEquals($this->defaultMimeType, $response->getHeader('content-type')[0]);
      $this->assertEquals('200', $response->getStatusCode(), 'HTTP response code is correct.');
      $this->assertEquals($first_rev, $response->getHeader('x-relaxed-etag')[0]);
      $this->assertTrue(empty((string) $response->getBody()), 'HEAD request returned no body.');

      $new_name = $this->randomMachineName();
      $entity->name = $new_name;
      $entity->save();
      $second_rev = $entity->_rev->value;

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'HEAD', NULL);
      $this->assertEquals($this->defaultMimeType, $response->getHeader('content-type')[0]);
      $this->assertEquals($second_rev, $response->getHeader('x-relaxed-etag')[0]);

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'HEAD', NULL, NULL, NULL, ['rev' => $first_rev]);
      $this->assertEquals($this->defaultMimeType, $response->getHeader('content-type')[0]);
      $this->assertEquals($first_rev, $response->getHeader('x-relaxed-etag')[0]);

      // Test the response for a fake revision.
      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'HEAD', NULL, NULL, NULL, ['rev' => '11112222333344445555']);
      $this->assertEquals('404', $response->getStatusCode(), 'HTTP response code is correct.');

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'HEAD', NULL, NULL, ['if-none-match' => $first_rev]);
      $this->assertEquals($this->defaultMimeType, $response->getHeader('content-type')[0]);
      $this->assertEquals($first_rev, $response->getHeader('x-relaxed-etag')[0]);

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'HEAD', NULL, NULL, ['if-none-match' => $second_rev]);
      $this->assertEquals($this->defaultMimeType, $response->getHeader('content-type')[0]);
      $this->assertEquals($second_rev, $response->getHeader('x-relaxed-etag')[0]);

      // Test the response for a fake revision using if-none-match header.
      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'HEAD', NULL, NULL, ['if-none-match' => '11112222333344445555']);
      $this->assertEquals('404', $response->getStatusCode(), 'HTTP response code is correct.');
    }
  }

  /**
   * Tests non-multipart GET requests.
   */
  public function testGet() {
    $entity_types = ['entity_test_rev'];
    foreach ($entity_types as $entity_type) {
      // Create a user with the correct permissions.
      $permissions = $this->entityPermissions($entity_type, 'view');
      $permissions[] = 'administer workspaces';
      $permissions[] = 'perform pull replication';
      $account = $this->drupalCreateUser($permissions);
      $this->drupalLogin($account);

      // We set this here just for testing.
      \Drupal::service('workspaces.manager')->setActiveWorkspace($this->workspace);

      $response = $this->httpRequest("$this->dbname/bogus", 'GET', NULL);
      $this->assertEquals('404', $response->getStatusCode(), 'HTTP response code is correct for non-existing entities.');

      $storage = $this->entityTypeManager->getStorage($entity_type)->useWorkspace($this->workspace->id());
      $entity = $storage->create();
      $entity->save();
      $entity = $storage->load($entity->id());

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'GET', NULL);
      $this->assertEquals('200', $response->getStatusCode(), 'HTTP response code is correct.');
      $this->assertEquals($this->defaultMimeType, $response->getHeader('content-type')[0]);
      $this->assertEquals($entity->_rev->value, $response->getHeader('x-relaxed-etag')[0]);
      $data = Json::decode($response->getBody());
      // Only assert one example property here, other properties should be
      // checked in serialization tests.
      $this->assertEquals($data['_rev'], $entity->_rev->value, 'GET request returned correct revision hash.');

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'GET', NULL, NULL, NULL, ['revs' => TRUE]);
      $data = Json::decode($response->getBody());
      $rev = $data['_revisions']['start'] . '-' . $data['_revisions']['ids'][0];
      $this->assertEquals($rev, $entity->_rev->value, 'GET request returned correct revision list after first revision.');

      // Save an additional revision.
      $entity->save();

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'GET', NULL, NULL, NULL, ['revs' => TRUE]);
      $data = Json::decode($response->getBody());
      $count = count($data['_revisions']['ids']);
      $this->assertEquals($count, 3, 'GET request returned correct revision list after second revision.');

      // Test the response for a fake revision.
      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'GET', NULL, NULL, NULL, ['rev' => '11112222333344445555']);
      $this->assertEquals('404', $response->getStatusCode(), 'HTTP response code is correct.');

      $entity = $this->entityTypeManager->getStorage($entity_type)->useWorkspace($this->workspace->id())->create();
      $entity->save();
      $first_rev = $entity->_rev->value;
      $entity->name = $this->randomMachineName();
      $entity->save();
      $second_rev = $entity->_rev->value;

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'GET', NULL, NULL, ['if-none-match' => $first_rev]);
      $this->assertEquals($this->defaultMimeType, $response->getHeader('content-type')[0]);
      $this->assertEquals($first_rev, $response->getHeader('x-relaxed-etag')[0]);

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'GET', NULL, NULL, ['if-none-match' => $second_rev]);
      $this->assertEquals($this->defaultMimeType, $response->getHeader('content-type')[0]);
      $this->assertEquals($second_rev, $response->getHeader('x-relaxed-etag')[0]);

      // Test the response for a fake revision using if-none-match header.
      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'GET', NULL, NULL, ['if-none-match' => '11112222333344445555']);
      $this->assertEquals('404', $response->getStatusCode(), 'HTTP response code is correct.');
    }
  }

  /**
   * Tests GET requests with multiple parts.
   */
  public function testGetOpenRevs() {
    $entity_types = ['entity_test_rev'];
    foreach ($entity_types as $entity_type) {
      // Create a user with the correct permissions.
      $permissions = $this->entityPermissions($entity_type, 'view');
      $permissions[] = 'administer workspaces';
      $permissions[] = 'perform pull replication';
      $account = $this->drupalCreateUser($permissions);
      $this->drupalLogin($account);

      // We set this here just for testing.
      \Drupal::service('workspaces.manager')->setActiveWorkspace($this->workspace);

      $storage = $this->entityTypeManager->getStorage($entity_type)->useWorkspace($this->workspace->id());
      $entity = $storage->create();
      $entity->save();
      $entity = $storage->load($entity->id());

      $entity->name = $this->randomMachineName();

      $open_revs = [];
      $open_revs[] = $entity->_rev->value;

      $open_revs_string = json_encode($open_revs);
      $response = $this->httpRequest(
        "$this->dbname/" . $entity->uuid(),
        'GET',
        NULL,
        'multipart/mixed',
        NULL,
        ['open_revs' => $open_revs_string, '_format' => 'mixed']
      );

      $stream = Psr7\stream_for($response->getBody());
      $parts = MultipartResponse::parseMultipartBody($stream);
      $this->assertEquals('200', $response->getStatusCode(), 'HTTP response code is correct.');

      $data = [];
      foreach ($parts as $part) {
        $data[] = Json::decode($part['body']);
      }

      $correct_data = TRUE;
      foreach ($open_revs as $key => $rev) {
        if (isset($data[$key]['_rev']) && $data[$key]['_rev'] != $rev) {
          $correct_data = FALSE;
        }
      }
      $this->assertTrue($correct_data, 'Multipart response contains correct revisions.');

      // Test a non-multipart request with open_revs.
      $response = $this->httpRequest(
        "$this->dbname/" . $entity->uuid(),
        'GET',
        NULL,
        $this->defaultMimeType,
        NULL,
        ['open_revs' => $open_revs_string]
      );
      $data = Json::decode($response->getBody());
      $correct_data = TRUE;
      foreach ($open_revs as $key => $rev) {
        if (isset($data[$key]['ok']['_rev']) && $data[$key]['ok']['_rev'] != $rev) {
          $correct_data = FALSE;
        }
      }
      $this->assertTrue($correct_data, 'Response contains correct revisions.');
    }
  }

  public function testPut() {
    $serializer = $this->container->get('relaxed.serializer');
    $entity_types = ['entity_test_rev'];
    foreach ($entity_types as $entity_type) {
      // Create a user with the correct permissions.
      $permissions = $this->entityPermissions($entity_type, 'create');
      $permissions[] = 'administer workspaces';
      $permissions[] = 'perform push replication';
      $account = $this->drupalCreateUser($permissions);
      $this->drupalLogin($account);

      // We set this here just for testing.
      \Drupal::service('workspaces.manager')->setActiveWorkspace($this->workspace);

      $entity = $this->entityTypeManager->getStorage($entity_type)->useWorkspace($this->workspace->id())->create(['user_id' => $account->id()]);
      $serialized = $serializer->serialize($entity, $this->defaultFormat);

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'PUT', $serialized);
      $this->assertEquals('201', $response->getStatusCode(), 'HTTP response code is correct');
      $data = Json::decode($response->getBody());
      $this->assertTrue(isset($data['rev']), 'PUT request returned a revision hash.');

      $storage = $this->entityTypeManager->getStorage($entity_type)->useWorkspace($this->workspace->id());
      $entity = $storage->create();
      $entity->save();
      $entity = $storage->load($entity->id());
      $first_rev = $entity->_rev->value;
      $entity->name = $this->randomMachineName();
      $entity->save();
      $second_rev = $entity->_rev->value;
      $serialized = $serializer->serialize($entity, $this->defaultFormat);

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'PUT', $serialized, NULL, ['if-match' => $first_rev]);
      $this->assertEquals('409', $response->getStatusCode(), 'HTTP response code is correct.');

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'PUT', $serialized, NULL, ['if-match' => $second_rev]);
      $this->assertEquals('201', $response->getStatusCode(), 'HTTP response code is correct.');
      $data = Json::decode($response->getBody());
      $this->assertTrue(isset($data['rev']), 'PUT request returned a revision hash.');

      $entity = $this->entityTypeManager->getStorage($entity_type)->useWorkspace($this->workspace->id())->load($entity->id());
      $serialized = $serializer->serialize($entity, $this->defaultFormat);

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'PUT', $serialized, NULL, NULL, ['rev' => $first_rev]);
      $this->assertEquals('409', $response->getStatusCode(), 'HTTP response code is correct.');

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'PUT', $serialized, NULL, NULL, ['rev' => $entity->_rev->value]);
      $this->assertEquals('201', $response->getStatusCode(), 'HTTP response code is correct.');
      $data = Json::decode($response->getBody());
      $this->assertTrue(isset($data['rev']), 'PUT request returned a revision hash.');
    }
  }

  public function testDelete() {
    $entity_types = ['entity_test_rev'];
    foreach ($entity_types as $entity_type) {
      // Create a user with the correct permissions.
      $permissions = $this->entityPermissions($entity_type, 'delete');
      $permissions[] = 'administer workspaces';
      $permissions[] = 'perform push replication';
      $account = $this->drupalCreateUser($permissions);
      $this->drupalLogin($account);

      // We set this here just for testing.
      \Drupal::service('workspaces.manager')->setActiveWorkspace($this->workspace);

      $storage = $this->entityTypeManager->getStorage($entity_type)->useWorkspace($this->workspace->id());
      $entity = $storage->create();
      $entity->save();
      $entity = $storage->load($entity->id());

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'DELETE', NULL);
      $this->assertEquals('200', $response->getStatusCode(), 'HTTP response code is correct for new database');
      $data = Json::decode($response->getBody());
      $this->assertTrue(!empty($data['ok']), 'DELETE request returned ok.');

      $entity = $this->entityTypeManager->getStorage($entity_type)->useWorkspace($this->workspace->id())->load($entity->id());
      $this->assertTrue(empty($entity), 'The entity being DELETED was not loaded.');

      $storage = $this->entityTypeManager->getStorage($entity_type)->useWorkspace($this->workspace->id());
      $entity = $storage->create();
      $entity->save();
      $entity = $storage->load($entity->id());
      $first_rev = $entity->_rev->value;
      $entity->name = $this->randomMachineName();
      $entity->save();
      $second_rev = $entity->_rev->value;

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'DELETE', NULL, NULL, NULL, ['rev' => $first_rev]);
      $this->assertEquals('409', $response->getStatusCode(), 'HTTP response code is correct.');

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'DELETE', NULL, NULL, NULL, ['rev' => $second_rev]);
      $this->assertEquals('200', $response->getStatusCode(), 'HTTP response code is correct.');
      $data = Json::decode($response->getBody());
      $this->assertTrue(!empty($data['ok']), 'DELETE request returned ok.');

      // Test the response for a fake revision.
      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'DELETE', NULL, NULL, NULL, ['rev' => '11112222333344445555']);
      $this->assertEquals('404', $response->getStatusCode(), 'HTTP response code is correct.');

      $storage = $this->entityTypeManager->getStorage($entity_type)->useWorkspace($this->workspace->id());
      $entity = $storage->create();
      $entity->save();
      $entity = $storage->load($entity->id());
      $first_rev = $entity->_rev->value;
      $entity->name = $this->randomMachineName();
      $entity->save();
      $second_rev = $entity->_rev->value;

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'DELETE', NULL, NULL, ['if-match' => $first_rev]);
      $this->assertEquals('200', $response->getStatusCode(), 'HTTP response code is correct.');

      $response = $this->httpRequest("$this->dbname/" . $entity->uuid(), 'DELETE', NULL, NULL, ['if-match' => $second_rev]);
      $this->assertEquals('200', $response->getStatusCode(), 'HTTP response code is correct.');
      $data = Json::decode($response->getBody());
      $this->assertTrue(!empty($data['ok']), 'DELETE request returned ok.');
    }
  }

  public function testStub() {
    $serializer = $this->container->get('relaxed.serializer');
    $entity_types = ['entity_test_rev'];
    foreach ($entity_types as $entity_type_id) {
      // Create a user with the correct permissions.
      $permissions = $this->entityPermissions($entity_type_id, 'create');
      $permissions[] = 'administer workspaces';
      $permissions[] = 'perform push replication';
      $permissions[] = 'administer users';
      $permissions[] = 'administer taxonomy';
      $account = $this->drupalCreateUser($permissions);
      $this->drupalLogin($account);

      // We set this here just for testing.
      \Drupal::service('workspaces.manager')->setActiveWorkspace($this->workspace);

      $entity_uuid = 'fe36b529-e2d7-4625-9b07-7ee8f84928b2';
      $reference_uuid = '0aec21a0-8e36-11e5-8994-feff819cdc9f';

      $normalized = [
        '@context' => [
          '@id' => '_id',
          '@language' => 'en'
        ],
        '@type' => $entity_type_id,
        '_id' => $entity_uuid,
        'en' => [
          'name' => [],
          'type' => [['value' => $entity_type_id]],
          'created' => [['value' => 1447877434]],
          'user_id' => [],
          'tags_list' => [[
            'entity_type_id' => 'taxonomy_term',
            'target_uuid' => $reference_uuid,
          ]],
        ],
      ];

      $response = $this->httpRequest("$this->dbname/" . $entity_uuid, 'PUT', Json::encode($normalized));
      $data = Json::decode($response->getBody());
      $this->assertEquals('201', $response->getStatusCode(), 'HTTP response code is correct');
      $this->assertTrue(isset($data['rev']), 'PUT request returned a revision hash.');

      $storage = $this->entityManager->getStorage('taxonomy_term')->useWorkspace($this->workspace->id());
      $referenced_terms = $storage->loadByProperties(['uuid' => $reference_uuid]);
      /** @var \Drupal\taxonomy\TermInterface $referenced_term */
      $referenced_term = reset($referenced_terms);

      $this->assertTrue(!empty($referenced_term), 'Referenced term way created.');
      $this->assertTrue($referenced_term->_rev->is_stub, 'References term was saved as stub.');

      $new_name = $this->randomMachineName();
      $referenced_term->name->value = $new_name;
      $serialized = $serializer->serialize($referenced_term, $this->defaultFormat);
      $response = $this->httpRequest("$this->dbname/" . $reference_uuid, 'PUT', $serialized);
      $data = Json::decode($response->getBody());
      $this->assertEquals('201', $response->getStatusCode(), 'HTTP response code is correct');
      $this->assertNotEquals('0-00000000000000000000000000000000', $data['rev'], 'PUT request returned a revision hash.');

      $referenced_terms = $storage->loadByProperties(['uuid' => $reference_uuid]);
      /** @var \Drupal\taxonomy\TermInterface $referenced_term */
      $referenced_term = reset($referenced_terms);
      $this->assertEquals($new_name, $referenced_term->name->value, 'The name was updated successfully.');
    }
  }

}
