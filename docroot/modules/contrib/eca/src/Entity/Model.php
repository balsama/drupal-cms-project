<?php

namespace Drupal\eca\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the ECA Model entity type.
 *
 * @deprecated in eca:3.0.0 and is removed from eca:3.1.0. Raw model data is now
 * owned by the Modeler API and will be stored in third-party settings or in
 * their own config entity.
 *
 * @see https://www.drupal.org/project/eca/issues/3517784
 */
#[ConfigEntityType(
  id: 'eca_model',
  label: new TranslatableMarkup('ECA Model'),
  label_collection: new TranslatableMarkup('ECA Models'),
  label_singular: new TranslatableMarkup('ECA Model'),
  label_plural: new TranslatableMarkup('ECA Models'),
  config_prefix: 'model',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  label_count: [
    'singular' => '@count ECA Model',
    'plural' => '@count ECA Models',
  ],
  config_export: [
    'id',
    'tags',
    'documentation',
    'modeldata',
  ]
)]
class Model extends ConfigEntityBase {

  /**
   * Get the tags of this model.
   *
   * @return array
   *   The tags of this model.
   */
  public function getTags(): array {
    return $this->get('tags') ?? [];
  }

  /**
   * Get the documentation of this model.
   *
   * @return string
   *   The documentation.
   */
  public function getDocumentation(): string {
    return $this->get('documentation') ?? '';
  }

  /**
   * Get the raw model data of this model.
   *
   * @return string
   *   The raw model data.
   */
  public function getModeldata(): string {
    return $this->get('modeldata') ?? '';
  }

}
