<?php

namespace Drupal\referenception\Plugin\Field\FieldFormatter;

use Drupal\entity_reference_revisions\Plugin\Field\FieldFormatter\EntityReferenceRevisionsFormatterBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldTypePluginManager;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * EntityReference formatter.
 *
 * @FieldFormatter(
 *   id = "referenception_formatter",
 *   label = @Translation("Referenceception Formatter"),
 *   field_types = {
 *     "entity_reference",
 *     "entity_reference_revisions"
 *   }
 * )
 */
class ReferenceptionFormatter extends EntityReferenceRevisionsFormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The field type plugin manager.
   *
   * @var \Drupal\Core\Field\FieldTypePluginManager
   */
  protected $fieldTypeManager;

  /**
   * The field formatter plugin manager.
   *
   * @var \Drupal\Core\Field\FormatterPluginManager
   */
  protected $fieldFormatterManager;

  /**
   * An array of available data.
   *
   * @var array
   */
  protected $data;

  /**
   * An array of available formatters.
   *
   * @var array
   */
  protected $formatters;

  /**
   * Constructs a StringFormatter instance.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Field\FieldTypePluginManager $field_type_manager
   *   The formatter type manager.
   * @param \Drupal\Core\Field\FormatterPluginManager $field_formatter_manager
   *   The formatter plugin manager.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, FieldTypePluginManager $field_type_manager, FormatterPluginManager $field_formatter_manager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->fieldTypeManager = $field_type_manager;
    $this->fieldFormatterManager = $field_formatter_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.field.field_type'),
      $container->get('plugin.manager.field.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'relationship' => '',
      'field' => '',
      'cardinality' => [],
      'formatter' => '',
      'settings' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    $relationship = $this->getSetting('relationship');
    $field = $this->getSetting('field');
    $formatter = $this->getSetting('formatter');
    $settings = $this->getSetting('settings');
    if (!empty($relationship) && !empty($field) && !empty($formatter)) {
      $entities = [$items->getEntity()];
      $child_entities = $this->getRelationshipEntity($entities, $this->getSetting('relationship'));
      $formatter_instance = $this->getFormatterInstance($relationship, $field, $formatter, $settings);
      if (!empty($formatter_instance)) {
        foreach ($child_entities as $child_entity) {
          $value = $child_entity->get($field)->getValue();
          $child_items = $this->fieldTypeManager->createFieldItemList($child_entity, $field, $value);
          $formatter_instance->prepareView([$child_items->getEntity()->id() => $child_items]);
          $elements[] = $formatter_instance->viewElements($child_items, $langcode);
        }
      }
    }

    return $elements;
  }

  /**
   * Given the relationship key, return the nested entities.
   *
   * @param array $entities
   *   The entities keyed by entity ID.
   * @param string $relationship_key
   *   The unique relationship key.
   *
   * @return array
   *   The child entities.
   */
  protected function getRelationshipEntity(array $entities, $relationship_key) {
    $parts = explode('|', $relationship_key);
    return $this->getRelationshipEntityRecursive($entities, $parts, $this->getSetting('cardinality'));
  }

  /**
   * Given the relationship key, return the nested entities.
   *
   * @param array $entities
   *   The entities keyed by entity ID.
   * @param array $parts
   *   An array of relationship parts containing field_name, entity_type,
   *   bundle.
   * @param array $cardinalities
   *   An array of cardinality.
   *
   * @return array
   *   The child entities.
   */
  protected function getRelationshipEntityRecursive(array $entities, array $parts, array $cardinalities) {
    $part = array_shift($parts);
    if (empty($part)) {
      return $entities;
    }
    $count = 0;
    $cardinality = array_shift($cardinalities);
    $amount = NULL;
    $offset = NULL;
    $reverse = $cardinality['reverse'];
    switch ($cardinality['mode']) {
      case 'advanced':
        $amount = $cardinality['amount'];
        $offset = $cardinality['offset'];
        break;

      case 'first':
        $amount = 1;
        $offset = 0;
        break;

      case 'last':
        $amount = 1;
        break;
    }
    $children = [];
    foreach ($entities as $entity) {
      list($field_name, $entity_type, $bundle) = explode(':', $part);
      if (isset($entity->{$field_name})) {
        $child_entities = $entity->{$field_name}->referencedEntities();
        // Last mode requires knowing how many entities we have at this point.
        if ($cardinality['mode'] == 'last') {
          $offset = count($child_entities) - 1;
        }
        foreach ($entity->{$field_name}->referencedEntities() as $delta => $child) {
          if ($child->getEntityTypeId() == $entity_type && $child->bundle() == $bundle) {
            if (isset($amount) && isset($offset)) {
              if ($delta >= $offset && $count < $amount) {
                $children[] = $child;
                $count++;
              }
            }
            else {
              $children[] = $child;
            }
          }
        }
      }
    }
    return $this->getRelationshipEntityRecursive($children, $parts, $cardinalities);
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $relationship = $this->getSetting('relationship');
    $field = $this->getSetting('field');
    $formatter = $this->getSetting('formatter');
    $settings = $this->getSetting('settings');
    if (!empty($relationship) && !empty($field) && !empty($formatter)) {
      // Relationship summary.
      $relationships = $this->getRelationshipOptions();
      if (isset($relationships[$relationship])) {
        $summary[] = $this->t('Relationship: @relationship', [
          '@relationship' => $relationships[$relationship],
        ]);
        // Field summary.
        $fields = $this->getFieldOptions($relationship);
        if (isset($fields[$field])) {
          $summary[] = $this->t('Field: @field', [
            '@field' => $fields[$field],
          ]);
          // Formatter summary.
          $formatters = $this->getFormatterOptions($relationship, $field);
          if (isset($formatters[$formatter])) {
            $summary[] = $this->t('Formatter: @formatter', [
              '@formatter' => $formatters[$formatter],
            ]);
            // Instance summary.
            $formatter_instance = $this->getFormatterInstance($relationship, $field, $formatter, $settings);
            if (!empty($formatter_instance)) {
              $summary = array_merge($summary, $formatter_instance->settingsSummary());
              // Thanks @grafikchaos!
            }
          }
          else {
            $summary[] = $this->t('Invalid formatter');
          }
        }
        else {
          $summary[] = $this->t('Invalid field');
        }
      }
      else {
        $summary[] = $this->t('Invalid relationship');
      }
    }
    else {
      $summary[] = $this->t('There is no referenception');
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);
    $field_name = $this->fieldDefinition->getName();
    $parents = ['fields', $field_name, 'settings_edit_form', 'settings'];
    $wrapper_id = 'referenception-' . str_replace('_', '-', $field_name);

    $elements['#type'] = 'container';
    $elements['#id'] = $wrapper_id;

    $elements['relationship'] = [
      '#type' => 'select',
      '#title' => $this->t('Relationship'),
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
      '#options' => $this->getRelationshipOptions(),
      '#default_value' => $this->getSetting('relationship'),
      '#ajax' => [
        'callback' => [get_class($this), 'settingsFormAjax'],
        'wrapper' => $wrapper_id,
      ],
    ];

    $relationship = $form_state->getValue([
      'fields', $field_name,
      'settings_edit_form',
      'settings',
      'relationship',
    ]) ?: $this->getSetting('relationship');
    if (!empty($relationship)) {

      $elements['cardinality'] = [
        '#type' => 'details',
        '#title' => $this->t('Cardinality'),
        '#tree' => TRUE,
        '#open' => FALSE,
      ];
      $this->settingsCardinalityForm($elements['cardinality'], $form_state, $relationship);

      $elements['field'] = [
        '#type' => 'select',
        '#title' => $this->t('Field'),
        '#empty_option' => $this->t('- Select -'),
        '#required' => TRUE,
        '#options' => $this->getFieldOptions($relationship),
        '#default_value' => $this->getSetting('field'),
        '#ajax' => [
          'callback' => [get_class($this), 'settingsFormAjax'],
          'wrapper' => $wrapper_id,
        ],
      ];
    }

    $field = $form_state->getValue([
      'fields',
      $field_name,
      'settings_edit_form',
      'settings',
      'field',
    ]) ?: $this->getSetting('field');
    if (!empty($field)) {

      $elements['formatter'] = [
        '#type' => 'select',
        '#title' => $this->t('Formatter'),
        '#empty_option' => $this->t('- Select -'),
        '#required' => TRUE,
        '#options' => $this->getFormatterOptions($relationship, $field),
        '#default_value' => $this->getSetting('formatter'),
        '#ajax' => [
          'callback' => [get_class($this), 'settingsFormAjax'],
          'wrapper' => $wrapper_id,
        ],
      ];
    }

    $formatter = $form_state->getValue([
      'fields',
      $field_name,
      'settings_edit_form',
      'settings',
      'formatter',
    ]) ?: $this->getSetting('formatter');
    if (!empty($formatter)) {
      $formatter_instance = $this->getFormatterInstance($relationship, $field, $formatter, $this->getSetting('settings'));
      if (!empty($formatter_instance)) {
        $settings_form = $formatter_instance->settingsForm($form, $form_state);
        if (!empty($settings_form)) {
          $elements['settings'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Settings'),
          ] + $settings_form;
        }
      }
    }

    return $elements;
  }

  /**
   * Get the formatter's selection mode options.
   *
   * @return array
   *   Array of available selection modes.
   */
  protected function getSelectionModes() {
    return [
      'all' => t('All'),
      'first' => t('First entity'),
      'last' => t('Last entity'),
      'advanced' => t('Advanced'),
    ];
  }

  /**
   * Build out the cardinality form.
   */
  protected function settingsCardinalityForm(array &$elements, FormStateInterface $form_state, $relationship_key) {

    $settings = $this->getSetting('cardinality');

    foreach ($this->getFlattenedData() as $key => $info) {
      if ($key == $relationship_key) {
        foreach ($info['cardinality'] as $delta => $data) {
          list($label, $cardinality) = array_values($data);

          $elements[$delta] = [
            '#type' => 'fieldset',
            '#title' => $this->t('<small>%label</small>', ['%label' => $label]),
          ];

          $elements[$delta]['mode'] = [
            '#type' => 'select',
            '#options' => $this->getSelectionModes(),
            '#title' => $this->t('Selection mode'),
            '#default_value' => isset($settings[$delta]['mode']) ? $settings[$delta]['mode'] : 'all',
            '#required' => TRUE,
          ];

          $show_advanced = [
            'visible' => [
              ':input[name="fields[' . $this->fieldDefinition->getName() . '][settings_edit_form][settings][cardinality][' . $delta . '][mode]"]' => [
                'value' => 'advanced',
              ],
            ],
          ];

          $elements[$delta]['amount'] = [
            '#type' => 'number',
            '#step' => 1,
            '#min' => 1,
            '#title' => t('Amount of displayed entities'),
            '#default_value' => isset($settings[$delta]['amount']) ? $settings[$delta]['amount'] : 1,
            '#states' => $show_advanced,
          ];
          if ($cardinality > 0) {
            $elements[$delta]['amount']['#max'] = $cardinality;
          }

          $elements[$delta]['offset'] = [
            '#type' => 'number',
            '#step' => 1,
            '#min' => 0,
            '#title' => t('Offset'),
            '#default_value' => isset($settings[$delta]['offset']) ? $settings[$delta]['offset'] : 0,
            '#states' => $show_advanced,
          ];

          $elements[$delta]['reverse'] = [
            '#type' => 'checkbox',
            '#title' => t('Reverse order'),
            '#desctiption' => t('Check this if you want to show the last added entities of the field. For example use amount 2 and "Reverse order" in order to display the last two entities in the field.'),
            '#default_value' => isset($settings[$delta]['reverse']) ? $settings[$delta]['reverse'] : 0,
            '#states' => $show_advanced,
          ];
        }
      }
    }
  }

  /**
   * Validation callback for the offset element.
   *
   * @param array $element
   *   The form element to process.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function validateOffset(array &$element, FormStateInterface $form_state) {
    // @see http://cgit.drupalcode.org/berf/tree/src/Plugin/Field/FieldFormatter/BetterEntityReferenceFormatter.php
  }

  /**
   * Ajax callback for the handler settings form.
   *
   * @see static::fieldSettingsForm()
   */
  public static function settingsFormAjax($form, FormStateInterface $form_state) {
    $element = $form_state->getTriggeringElement();
    // Go one level up in the form, to the widgets container.
    $elements = NestedArray::getValue($form, array_slice($element['#array_parents'], 0, -1));
    return $elements;
  }

  /**
   * Get relationship options.
   *
   * @return array
   *   The options.
   */
  protected function getRelationshipOptions() {
    $options = [];
    foreach ($this->getFlattenedData() as $key => $info) {
      $options[$key] = $info['label'];
    }
    return $options;
  }

  /**
   * Get field options.
   *
   * @return array
   *   The options.
   */
  protected function getFieldOptions($relationship_key) {
    $options = [];
    foreach ($this->getFlattenedData() as $key => $info) {
      if ($key == $relationship_key) {
        foreach ($info['fields'] as $field_id => $field_data) {
          $options[$field_id] = $field_data['field_definition']->getLabel();
        }
      }
    }
    return $options;
  }

  /**
   * Get field options.
   *
   * @return array
   *   The options.
   */
  protected function getFormatterOptions($relationship_key, $field_key) {
    $options = [];
    foreach ($this->getFlattenedData() as $key => $info) {
      if ($key == $relationship_key) {
        foreach ($info['fields'] as $field_id => $field_data) {
          if ($field_id == $field_key) {
            foreach ($field_data['formatters'] as $formatter_id => $formatter) {
              $options[$formatter_id] = $formatter['label'];
            }
          }
        }
      }
    }
    return $options;
  }

  /**
   * Get field formatter.
   *
   * @return array
   *   The form.
   */
  protected function getFormatterInstance($relationship_key, $field_key, $formatter_key, $settings = []) {
    foreach ($this->getFlattenedData() as $key => $info) {
      if ($key == $relationship_key) {
        foreach ($info['fields'] as $field_id => $field_data) {
          if ($field_id == $field_key) {
            foreach ($field_data['formatters'] as $formatter_id => $formatter) {
              if ($formatter_id == $formatter_key) {
                $options = [
                  'field_definition' => $field_data['field_definition'],
                  'view_mode' => $this->viewMode,
                  'settings' => $settings,
                  'configuration' => ['type' => $formatter_id, 'settings' => $settings],
                ];
                return $this->fieldFormatterManager->getInstance($options);
              }
            }
          }
        }
      }
    }
    return [];
  }

  /**
   * Gets possible entity types for a given entity type.
   *
   * @return array
   *   Field info array.
   */
  protected function getFlattenedData() {
    $data = $this->getData();
    return $this->getFlattenedDataRecursive($data);
  }

  /**
   * Helper that recursively iterates through field data.
   *
   * @param array $data
   *   A nested array containing entity_type => bundle => [fields, children].
   * @param string $key
   *   A key used to define the flat nesting of references.
   * @param string $label
   *   The label.
   * @param array $label
   *   The cardinality.
   *
   * @return array
   *   Field info array.
   */
  protected function getFlattenedDataRecursive($data = [], $key = '', $label = '', array $cardinality = []) {
    $return = [];
    foreach ($data as $entity_type_id => $info) {
      foreach ($info['bundles'] as $bundle_id => $bundle_data) {
        $id = $key . $info['field_definition']->getName() . ':' . $entity_type_id . ':' . $bundle_id;

        // Build label.
        $name = $info['entity_definition']->getLabel() . ' (' . $info['field_definition']->getName() . ')';
        if ($bundle_data['bundle_definition']) {
          $name .= ': ' . $bundle_data['bundle_definition']->label();
        }
        $name = empty($label) ? $name : $label . ' > ' . $name;
        $return[$id]['label'] = $name;

        // Build cardinality.
        $cardinality[] = [
          'label' => $name,
          'value' => $info['cardinality'],
        ];
        $return[$id]['cardinality'] = $cardinality;

        foreach ($bundle_data['fields'] as $field_id => $field_data) {
          $return[$id]['fields'][$field_id] = $field_data;
        }

        foreach ($bundle_data['relationships'] as $field_id => $relationship_data) {
          $return += $this->getFlattenedDataRecursive($relationship_data, $id . '|', $name, $cardinality);
        }
      }
    }
    return $return;
  }

  /**
   * Gets data for a given entity type.
   *
   * @return array
   *   Data info array.
   */
  protected function getData() {
    if (!isset($this->data)) {
      $this->data = $this->loadData($this->fieldDefinition);
    }
    return $this->data;
  }

  /**
   * Load possible fields for a given entity type.
   *
   * This is an expensive operation so use getData() so that results are
   * cached.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field definition.
   *
   * @return array
   *   Field info array.
   */
  protected function loadData(FieldDefinitionInterface $field) {
    $return = [];

    if (in_array($field->getType(), ['entity_reference', 'entity_reference_revisions']) && $field->isDisplayConfigurable('view')) {
      $settings = $field->getSettings();
      $entity_type = $settings['target_type'];
      $bundles = isset($settings['handler_settings']['target_bundles']) ? $settings['handler_settings']['target_bundles'] : [$entity_type];

      $entity_type_object = $this->entityTypeManager->getDefinition($entity_type);
      if ($entity_type_object->entityClassImplements('\Drupal\Core\Entity\FieldableEntityInterface')) {
        $return[$entity_type]['entity_definition'] = $entity_type_object;
        $return[$entity_type]['field_definition'] = $field;
        $return[$entity_type]['cardinality'] = $field->getFieldStorageDefinition()->getCardinality();
        foreach ($bundles as $bundle) {
          $data = [];
          $data['bundle_definition'] = $entity_type_object->getBundleEntityType() ? $this->entityTypeManager->getStorage($entity_type_object->getBundleEntityType())->load($bundle) : NULL;
          $data['relationships'] = [];
          $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
          foreach ($fields as $field_name => $field_definition) {
            $formatters = $this->getFormatters($field_definition->getType());
            if (!empty($formatters)) {
              $data['fields'][$field_name]['field_definition'] = $field_definition;
              $data['fields'][$field_name]['formatters'] = $formatters;
              $relationships = $this->loadData($field_definition);
              if (!empty($relationships)) {
                $data['relationships'][$field_name] = $relationships;
              }
            }
          }
          $return[$entity_type]['bundles'][$bundle] = $data;
        }
      }
    }

    return $return;
  }

  /**
   * Gets formatters for the given field type.
   *
   * @param string $field_type
   *   The field type id.
   *
   * @return array
   *   Formatters info array.
   */
  protected function getFormatters($field_type) {
    if (!isset($this->formatters)) {
      $this->formatters = $this->fieldFormatterManager->getDefinitions();
    }

    $formatters = [];
    foreach ($this->formatters as $formatter => $formatter_info) {
      if (in_array($field_type, $formatter_info['field_types'])) {
        $formatters[$formatter] = $formatter_info;
      }
    }
    return $formatters;
  }

}
