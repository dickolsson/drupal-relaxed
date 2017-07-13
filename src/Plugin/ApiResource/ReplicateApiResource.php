<?php

namespace Drupal\relaxed\Plugin\ApiResource;

use Drupal\relaxed\Http\ApiResourceResponse;

/**
 * @ApiResource(
 *   id = "replicate",
 *   label = "Replicate",
 *   serialization_class = {
 *     "canonical" = "Drupal\relaxed\Replicate\Replicate",
 *   },
 *   path = "/_replicate"
 * )
 */
class ReplicateApiResource extends ApiResourceBase {

  /**
   * @param \Drupal\relaxed\Replicate\Replicate $replicate
   *
   * @return \Drupal\relaxed\Http\ApiResourceResponse
   */
  public function post($replicate) {
    $replicate->doReplication();

    return new ApiResourceResponse($replicate, 201);
  }
}
