<?php

/**
 * @file
 * Contains \Drupal\relaxed\Plugin\rest\resource\BulkDocsResource.
 */

namespace Drupal\relaxed\Plugin\rest\resource;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\multiversion\Entity\WorkspaceInterface;
use Drupal\relaxed\BulkDocs\BulkDocsInterface;
use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @RestResource(
 *   id = "relaxed:bulk_docs",
 *   label = "Bulk documents",
 *   serialization_class = {
 *     "canonical" = "Drupal\relaxed\BulkDocs\BulkDocs",
 *   },
 *   uri_paths = {
 *     "canonical" = "/{db}/_bulk_docs",
 *   }
 * )
 */
class BulkDocsResource extends ResourceBase {

  /**
   * @param string | \Drupal\multiversion\Entity\WorkspaceInterface $workspace
   * @param \Drupal\relaxed\BulkDocs\BulkDocsInterface $bulk_docs
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Exception thrown if $workspace is not a loaded entity.
   *
   * @return \Drupal\rest\ResourceResponse
   */
  public function post($workspace, $bulk_docs) {
    if (is_string($workspace)) {
      throw new NotFoundHttpException();
    }

    $bulk_docs->save();
    return new ResourceResponse($bulk_docs, 201);
  }
}
