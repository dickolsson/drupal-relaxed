<?php

namespace Drupal\Tests\relaxed\Functional;

use Drupal\Component\Serialization\Json;

/**
 * Tests the /db/_changes resource.
 *
 * @group relaxed
 */
class ChangesResourceTest extends ResourceTestBase {

  public function testGet() {
    $serializer = \Drupal::service('relaxed.serializer');

    // Create a user with the correct permissions.
    $permissions[] = 'administer workspaces';
    $permissions[] = 'perform pull replication';
    $account = $this->drupalCreateUser($permissions);
    $this->drupalLogin($account);

    // We set this here just to test creation, saving and then getting
    // (with 'relaxed:changes') changes on the same workspace.
    $this->workspaceManager->setActiveWorkspace($this->workspace);

    $expected_with_docs = $expected_without_docs = ['last_seq' => NULL, 'results' => []];

    $entity = $this->entityTypeManager->getStorage('entity_test_rev')->useWorkspace($this->workspace->id())->create();
    $entity->save();
    // Update the field_test_text field.
    $entity->set('field_test_text', [['value' => $this->randomString(), 'format' => 'plain_text']]);
    $entity->save();

    // Update the name filed.
    $entity->set('name', [['value' => $this->randomString(12), 'format' => 'plain_text']]);
    $entity->save();

    // Update the name filed again.
    $entity->set('name', [['value' => $this->randomString(25), 'format' => 'plain_text']]);
    $entity->save();
    $first_seq = $this->multiversionManager->lastSequenceId();
    $expected_without_docs['results'][] = [
      'changes' => [['rev' => $entity->_rev->value]],
      'id' => $entity->uuid(),
      'seq' => $first_seq,
    ];
    $expected_with_docs['results'][] = [
      'changes' => [['rev' => $entity->_rev->value]],
      'id' => $entity->uuid(),
      'seq' => $first_seq,
      'doc' => $serializer->normalize($entity)
    ];

    // Create a new entity.
    $entity = $this->entityTypeManager->getStorage('entity_test_rev')->useWorkspace($this->workspace->id())->create();
    $entity->save();

    // Update the field_test_text field.
    $entity->set('field_test_text', [['value' => $this->randomString(), 'format' => 'plain_text']]);
    $entity->save();

    // Delete the entity.
    $entity->delete();
    $second_seq = $this->multiversionManager->lastSequenceId();
    $expected_without_docs['results'][] = [
      'changes' => [['rev' => $entity->_rev->value]],
      'id' => $entity->uuid(),
      'seq' => $second_seq,
      'deleted' => true,
    ];
    $expected_with_docs['results'][] = [
      'changes' => [['rev' => $entity->_rev->value]],
      'id' => $entity->uuid(),
      'seq' => $second_seq,
      'doc' => $serializer->normalize($entity),
      'deleted' => true,
    ];

    $expected_with_docs['last_seq'] = $expected_without_docs['last_seq'] = $this->multiversionManager->lastSequenceId();

    $response = $this->httpRequest("$this->dbname/_changes", 'GET', NULL, $this->defaultMimeType);
    $this->assertSame($response->getStatusCode(), 200, 'HTTP response code is correct when not including docs.');
    $this->assertSame($this->defaultMimeType, $response->getHeader('content-type')[0]);

    $data = Json::decode($response->getBody());
    $this->assertEquals($data, $expected_without_docs, 'The result is correct when not including docs.');

    $response = $this->httpRequest("$this->dbname/_changes", 'GET', NULL, $this->defaultMimeType, NULL, ['include_docs' => 'true']);
    $this->assertSame($response->getStatusCode(), 200, 'HTTP response code is correct when including docs.');
    $this->assertSame($this->defaultMimeType, $response->getHeader('content-type')[0]);

    $data = Json::decode($response->getBody());
    $this->assertEquals($data, $expected_with_docs, 'The result is correct when including docs.');

    // Test when using 'since' query parameter.
    $response = $this->httpRequest("$this->dbname/_changes", 'GET', NULL, $this->defaultMimeType, NULL, ['since' => 1]);
    $this->assertSame($response->getStatusCode(), 200, 'HTTP response code is correct when not including docs.');
    $this->assertSame($this->defaultMimeType, $response->getHeader('content-type')[0]);

    $data = Json::decode($response->getBody());
    $this->assertEquals($data, $expected_without_docs, 'The result is correct when not including docs.');

    $response = $this->httpRequest("$this->dbname/_changes", 'GET', NULL, $this->defaultMimeType, NULL, ['since' => $first_seq]);
    $this->assertSame($response->getStatusCode(), 200, 'HTTP response code is correct when not including docs.');
    $this->assertSame($this->defaultMimeType, $response->getHeader('content-type')[0]);

    $data = Json::decode($response->getBody());
    // Unset first value from results, it shouldn't be returned when since == $first_seq.
    unset($expected_without_docs['results'][0]);
    // Reset the keys of the results array.
    $expected_without_docs['results'] = array_values($expected_without_docs['results']);
    $this->assertEquals($data, $expected_without_docs, 'The result is correct when not including docs.');

    $response = $this->httpRequest("$this->dbname/_changes", 'GET', NULL, $this->defaultMimeType, NULL, ['since' => $second_seq]);
    $this->assertSame($response->getStatusCode(), 200, 'HTTP response code is correct when not including docs.');
    $this->assertSame($this->defaultMimeType, $response->getHeader('content-type')[0]);

    $data = Json::decode($response->getBody());
    // The result array should be empty in this case.
    $expected_without_docs['results'] = [];
    // And last_seq == 0.
    $expected_without_docs['last_seq'] = 0;
    $this->assertEquals($data, $expected_without_docs, 'The result is correct when not including docs.');

    // @todo: {@link https://www.drupal.org/node/2600488 Assert the sort order.}
  }

}