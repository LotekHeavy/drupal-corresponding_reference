<?php

namespace Drupal\corresponding_reference\Form;

use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Form\FormStateInterface;
use Drupal\corresponding_reference\Entity\CorrespondingReferenceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for corresponding reference add and edit forms.
 */
class CorrespondingReferenceForm extends EntityForm {

  /** @var \Drupal\Core\Entity\Query\QueryFactory */
  protected $entityQuery;

  /** @var  EntityFieldManager */
  protected $fieldManager;

  /**
   * Constructs a CorrespondingReferenceForm object.
   *
   * @param \Drupal\Core\Entity\Query\QueryFactory $entity_query
   *   The entity query.
   *
   * @param \Drupal\Core\Entity\EntityFieldManager $field_manager
   *   The entity field manager.
   */
  public function __construct(QueryFactory $entity_query, EntityFieldManager $field_manager) {
    $this->entityQuery = $entity_query;
    $this->fieldManager = $field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    /** @var QueryFactory $entity_query */
    $entity_query = $container->get('entity.query');

    /** @var EntityFieldManager $field_manager */
    $field_manager = $container->get('entity_field.manager');

    return new static(
      $entity_query,
      $field_manager
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    /** @var CorrespondingReferenceInterface $correspondingReference */
    $correspondingReference = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $correspondingReference->label(),
      '#description' => $this->t("Label for the corresponding reference."),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $correspondingReference->id(),
      '#machine_name' => [
        'exists' => [$this, 'exists'],
      ],
      '#disabled' => !$correspondingReference->isNew(),
    ];

    $form['first_field'] = [
      '#type' => 'select',
      '#title' => $this->t('First field'),
      '#description' => $this->t('Select the first field.'),
      '#options' => $this->getFieldOptions(),
      '#default_value' => $correspondingReference->getFirstField(),
      '#required' => TRUE,
    ];

    $form['second_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Second field'),
      '#description' => $this->t('Select the corresponding field. It may be the same field.'),
      '#options' => $this->getFieldOptions(),
      '#default_value' => $correspondingReference->getSecondField(),
      '#required' => TRUE,
    ];

    $form['bundles'] = [
      '#type' => 'select',
      '#title' => $this->t('Bundles'),
      '#description' => $this->t('Select the bundles which should correspond to one another when they have one of the corresponding fields.'),
      '#options' => $this->getBundleOptions(),
      '#multiple' => TRUE,
      '#default_value' => $this->getBundleValuesForForm($correspondingReference->getBundles()),
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#description' => $this->t('When enabled, corresponding references will be automatically created upon saving an entity.'),
      '#default_value' => $correspondingReference->isEnabled(),
    ];

    return $form;
  }

  protected function getReferenceFieldMap() {
    $map = $this->fieldManager->getFieldMapByFieldType('entity_reference');

    return $map;
  }

  protected function getFieldOptions() {
    $options = [];

    foreach ($this->getReferenceFieldMap() as $entityType => $entityTypeFields) {
      foreach ($entityTypeFields as $fieldName => $field) {
        if (!preg_match('/^field_.*$/', $fieldName)) {
          continue;
        }

        $options[$fieldName] = $fieldName;
      }
    }

    return $options;
  }

  protected function getBundleOptions() {
    /** @var CorrespondingReferenceInterface $correspondingReference */
    $correspondingReference = $this->entity;

    $correspondingFields = $correspondingReference->getCorrespondingFields();

    $options = [];

    foreach ($this->getReferenceFieldMap() as $entityType => $entityTypeFields) {
      $includeType = FALSE;

      foreach ($entityTypeFields as $fieldName => $field) {
        if (!empty($correspondingFields) && !in_array($fieldName, $correspondingFields)) {
          continue;
        }

        if (!preg_match('/^field_.*$/', $fieldName)) {
          continue;
        }

        $includeType = TRUE;

        foreach ($field['bundles'] as $bundle) {
          $options["$entityType:$bundle"] = "$entityType: $bundle";
        }
      }

      if ($includeType) {
        $options["$entityType:*"] = "$entityType: *";
      }
    }

    ksort($options);

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var CorrespondingReferenceInterface $correspondingReference */
    $correspondingReference = $this->entity;

    $status = $correspondingReference->save();

    if ($status) {
      drupal_set_message($this->t('Saved the %label corresponding reference.', [
        '%label' => $correspondingReference->label(),
      ]));
    }
    else {
      drupal_set_message($this->t('The %label corresponding reference was not saved.', [
        '%label' => $correspondingReference->label(),
      ]));
    }

    $form_state->setRedirect('entity.corresponding_reference.collection');
  }

  protected function getBundleValuesForForm(array $values = NULL) {
    $formValues = [];

    if (!is_null($values)) {
      foreach ($values as $entityType => $bundles) {
        foreach ($bundles as $bundle) {
          $formValues[] = "$entityType:$bundle";
        }
      }
    }

    return $formValues;
  }

  protected function getBundleValuesForEntity(array $values = NULL) {
    $entityValues = [];

    if (!is_null($values)) {
      foreach ($values as $value) {
        list($entityType, $bundle) = explode(':', $value);

        $entityValues[$entityType][] = $bundle;
      }
    }

    return $entityValues;
  }

  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    if ($this->entity instanceof EntityWithPluginCollectionInterface) {
      // Do not manually update values represented by plugin collections.
      $values = array_diff_key($values, $this->entity->getPluginCollections());
    }

    /** @var CorrespondingReferenceInterface $entity */
    $entity->set('id', $values['id']);
    $entity->set('label', $values['label']);
    $entity->set('first_field', $values['first_field']);
    $entity->set('second_field', $values['second_field']);
    $entity->set('bundles', $this->getBundleValuesForEntity($values['bundles']));
    $entity->set('enabled', $values['enabled']);
  }

  /**
   * Helper function to check whether a corresponding reference configuration entity exists.
   */
  public function exists($id) {
    $entity = $this->entityQuery->get('corresponding_reference')
      ->condition('id', $id)
      ->execute();
    return (bool) $entity;
  }
}
