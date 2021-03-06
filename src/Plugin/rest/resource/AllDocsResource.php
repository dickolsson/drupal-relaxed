<?php

namespace Drupal\relaxed\Plugin\rest\resource;

use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @RestResource(
 *   id = "relaxed:all_docs",
 *   label = "All Docs",
 *   serialization_class = {
 *     "canonical" = "Drupal\relaxed\AllDocs\AllDocs",
 *   },
 *   uri_paths = {
 *     "canonical" = "/{db}/_all_docs",
 *   }
 * )
 */
class AllDocsResource extends ResourceBase {

  /**
   * @param string | \Drupal\Core\Config\Entity\ConfigEntityInterface $workspace
   *
   * @return \Drupal\rest\ResourceResponse
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   */
  public function get($workspace) {
    $this->checkWorkspaceExists($workspace);

    $all_docs = \Drupal::service('replication.alldocs_factory')->get($workspace);

    $request = Request::createFromGlobals();
    if ($request->query->get('include_docs') == 'true') {
      $all_docs->includeDocs(TRUE);
    }

    $response = new ResourceResponse($all_docs, 200);
    foreach (\Drupal::service('multiversion.manager')->getSupportedEntityTypes() as $entity_type) {
      $response->addCacheableDependency($entity_type);
    }
    return $response;
  }

}
