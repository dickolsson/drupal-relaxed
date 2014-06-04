<?php

namespace Drupal\relaxed\Normalizer;

use Drupal\serialization\Normalizer\NormalizerBase;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class LocalSequenceItemListNormalizer extends NormalizerBase implements DenormalizerInterface {

  protected $supportedInterfaceOrClass = array('Drupal\multiversion\Plugin\Field\FieldType\LocalSequenceItemList');

  /**
   * {@inheritdoc}
   */
  public function normalize($field, $format = NULL, array $context = array()) {
    return $field->id;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = array()) {
    return array(array('id' => $data));
  }
}
