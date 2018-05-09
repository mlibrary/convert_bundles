<?php

namespace Drupal\convert_bundles\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\convert_bundles\ConvertBundles;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\user\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Routing\RouteBuilderInterface;

/**
 * ConvertBundlesForm.
 */
class ConvertBundlesForm extends FormBase implements FormInterface {

  /**
   * Set a var to make stepthrough form.
   *
   * @var step
   */
  protected $step = 1;
  /**
   * Set some entity vars.
   *
   * @var entities
   */
  protected $entities = NULL;
  /**
   * Set some content type vars.
   *
   * @var entityType
   */
  protected $entityType = NULL;
  /**
   * Set some content type vars.
   *
   * @var allBundles
   */
  protected $allBundles = NULL;
  /**
   * Set some content type vars.
   *
   * @var fromType
   */
  protected $fromType = NULL;
  /**
   * Set some content type vars.
   *
   * @var toType
   */
  protected $toType = NULL;
  /**
   * Set field vars.
   *
   * @var fieldsFrom
   */
  protected $fieldsFrom = NULL;
  /**
   * Set field vars.
   *
   * @var fieldsTo
   */
  protected $fieldsTo = NULL;
  /**
   * Create new based on to content type.
   *
   * @var createNew
   */
  protected $createNew = NULL;
  /**
   * Create new based on to content type.
   *
   * @var fields_new_to
   */
  protected $fieldsNewTo = NULL;
  /**
   * Keep track of user input.
   *
   * @var userInput
   */
  protected $userInput = [];

  /**
   * The entity query factory service.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  protected $entityManager;

  /**
   * Tempstorage.
   *
   * @var tempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * Session.
   *
   * @var sessionManager
   */
  private $sessionManager;

  /**
   * User.
   *
   * @var currentUser
   */
  private $currentUser;

  /**
   * The route builder.
   *
   * @var \Drupal\Core\Routing\RouteBuilderInterface
   */
  protected $routeBuilder;

  /**
   * Constructs a \Drupal\convert_bundles\Form\ConvertBundlesForm.
   *
   * @param \Drupal\user\PrivateTempStoreFactory $temp_store_factory
   *   Temp storage.
   * @param \Drupal\Core\Session\SessionManagerInterface $session_manager
   *   Session.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   User.
   * @param \Drupal\Core\Routing\RouteBuilderInterface $route_builder
   *   Route.
   */
  public function __construct(PrivateTempStoreFactory $temp_store_factory, SessionManagerInterface $session_manager, AccountInterface $current_user, QueryFactory $entity_query, EntityFieldManager $entity_manager, RouteBuilderInterface $route_builder) {
    $this->tempStoreFactory = $temp_store_factory;
    $this->sessionManager = $session_manager;
    $this->currentUser = $current_user;
	$this->entityQuery = $entity_query;
    $this->entityManager = $entity_manager;
    $this->routeBuilder = $route_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user.private_tempstore'),
      $container->get('session_manager'),
      $container->get('current_user'),
      $container->get('entity.query'),
      $container->get('entity_field.manager'),
      $container->get('router.builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'convert_bundles_form';
  }

  /**
   * {@inheritdoc}
   */
  public function updateFields() {
    $entities = $this->entities;
    $fields = $this->userInput['fields'];
    $batch = [
      'title' => $this->t('Updating Fields...'),
      'operations' => [
        [
          '\Drupal\bulk_update_fields\BulkUpdateFields::updateFields',
          [$entities, $fields],
        ],
      ],
      'finished' => '\Drupal\bulk_update_fields\BulkUpdateFields::bulkUpdateFieldsFinishedCallback',
    ];
    batch_set($batch);
    return 'All fields were updated successfully';
  }

  /**
   * {@inheritdoc}
   */
  public function ConvertBundles() {
    $base_table_names = ConvertBundles::getBaseTableNames();
    $userInput = ConvertBundles::sortUserInput($this->userInput, $this->fieldsNewTo, $this->fieldsFrom);
    $map_fields = $userInput['map_fields'];
    $update_fields = $userInput['update_fields'];
    $field_table_names = ConvertBundles::getFieldTableNames($this->fieldsFrom);
    $nids = array_keys($this->entities);
    $limit = 100;
    $batch = [
      'title' => $this->t('Converting Base Tables...'),
      'operations' => [
        [
          '\Drupal\convert_bundles\ConvertBundles::convertBaseTables',
          [$base_table_names, $nids, $this->toType],
        ],
        [
          '\Drupal\convert_bundles\ConvertBundles::convertFieldTables',
          [$field_table_names, $nids, $this->toType, $update_fields],
        ],
        [
          '\Drupal\convert_bundles\ConvertBundles::addNewFields',
          [$nids, $limit, $map_fields],
        ],
      ],
      'finished' => '\Drupal\convert_bundles\ConvertBundles::ConvertBundlesFinishedCallback',
    ];
    batch_set($batch);
    return 'Selected entities of type ' . implode(', ', $this->fromType) . ' were converted to ' . $this->toType;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    switch ($this->step) {
      case 1:
        $form_state->setRebuild();
        $this->fromType = array_filter($form_state->getValues()['convert_bundles_from']);
        break;
      case 2:
        $form_state->setRebuild();
        $this->toType = $form_state->getValues()['convert_bundles_to'];
        break;
      case 3:
        $form_state->setRebuild();
        $data_to_process = array_diff_key(
                            $form_state->getValues(),
                            array_flip(
                              [
                                'op',
                                'submit',
                                'form_id',
                                'form_build_id',
                                'form_token',
                              ]
                            )
                          );
        $this->userInput = $data_to_process;
        break;

      case 4:
        $this->createNew = $form['create_new']['#value'];
        if (!$this->createNew) {
          $this->step++;
          goto five;
        }
        $form_state->setRebuild();
        break;

      case 5:
        $values = $form_state->getValues()['default_value_input'];
        foreach ($values as $key => $value) {
          unset($values[$key]['add_more']);
        }
        $data_to_process = array_diff_key(
                            $values,
                            array_flip(
                              [
                                'op',
                                'submit',
                                'form_id',
                                'form_build_id',
                                'form_token',
                              ]
                            )
                          );
        $this->userInput = array_merge($this->userInput, $data_to_process);
        // Used also for goto.
        five:
        $form_state->setRebuild();
        break;

      case 6:
        if (method_exists($this, 'ConvertBundles')) {
          $return_verify = $this->ConvertBundles();
        }
        drupal_set_message($return_verify);
        $this->routeBuilder->rebuild();
        break;
    }
    $this->step++;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    if (isset($this->form)) {
      $form = $this->form;
    }
    $form['#title'] = $this->t('Convert Bundles');
    $submit_label = 'Next';

    switch ($this->step) {
      case 1:
        // Retrieve IDs from the temporary storage.
        $this->entities = $this->tempStoreFactory
          ->get('convert_bundles_ids')
          ->get($this->currentUser->id());
        $options = [];
        $entity_types = [];
        foreach ($this->entities as $entity) {
          $bundles[] = $entity->bundle();
          $entity_types[] = $entity->getEntityTypeId();
        }
        $bundle_info = \Drupal::service("entity_type.bundle.info")->getAllBundleInfo();
        if (count($entity_type = array_unique($entity_types)) > 1) {
          drupal_set_message('We cant convert multiple types of entities at once', 'error');
        }
        $this->entityType = $entity_type[0];
        $all_bundles = $bundle_info[$entity_type[0]];
        foreach ($all_bundles as $machine_name => $bundle_array) {
          $this->allBundles[$machine_name] = $bundle_array['label'];
        }
        foreach (array_unique($bundles) as $bundle) {
          $options[$bundle]['bundle_names'] = $all_bundles[$bundle]['label'];
        }
        $header = [
          'bundle_names' => $this->t('Bundle Name(s)'),
        ];
        $form['#title'] .= ' - ' . $this->t('Select Bundles to Convert (Bundles are from all entities selected)');
        $form['convert_bundles_from'] = [
          '#type' => 'tableselect',
          '#header' => $header,
          '#options' => $options,
          '#empty' => $this->t('No bundles found'),
        ];
        break;
      case 2:
        $form['#title'] .= ' - ' . $this->t('Select the Bundle to Convert Selected Entities to');
        $form['convert_bundles_to'] = [
          '#type' => 'select',
          '#title' => $this->t('To Bundle'),
          '#options' => $this->allBundles,
        ];
        break;
      case 3:
        // Get the fields.
        $entityManager = $this->entityManager;
        foreach ($this->fromType as $from_bundle) {
          $this->fieldsFrom[$from_bundle] = $entityManager->getFieldDefinitions($this->entityType, $from_bundle);
        }
        $this->fieldsTo = $entityManager->getFieldDefinitions($this->entityType, $this->toType);
        $fields_to = ConvertBundles::getToFields($this->fieldsTo);
        $fields_to_names = $fields_to['fields_to_names'];
        $fields_to_types = $fields_to['fields_to_types'];

        $fields_from = ConvertBundles::getFromFields($this->fieldsFrom, $fields_to_names, $fields_to_types);
        $fields_from_names = $fields_from['fields_from_names'];
        $fields_from_form = $fields_from['fields_from_form'];

        // Find missing fields. allowing values to be input later.
        $fields_to_names = array_diff($fields_to_names, ['remove', 'append_to_body']);
        $this->fieldsNewTo = array_diff(array_keys($fields_to_names), $fields_from_names);

        $form = array_merge($form, $fields_from_form);

        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Next'),
          '#button_type' => 'primary',
        ];
        break;

      case 4:
        $form['create_new'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Create field values for new fields in target content type'),
        ];
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Next'),
          '#button_type' => 'primary',
        ];
        break;

      case 5:
        // Put the to fields in the form for new values.
        foreach ($this->fieldsNewTo as $field_name) {
          if (!in_array($field_name, $this->userInput['field_convert_map'])) {
            // TODO - Date widgets are relative. Fix.
            // Create an arbitrary entity object.
            $ids = (object) [
              'entity_type' => $this->entityType,
              'bundle' => $this->toType,
              'entity_id' => NULL,
            ];
            $fake_entity = _field_create_entity_from_ids($ids);
            $items = $fake_entity->get($field_name);
            $temp_form_element = [];
            $temp_form_state = new FormState();
            $form[$field_name] = $items->defaultValuesForm($temp_form_element, $temp_form_state);
          }
        }
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Next'),
          '#button_type' => 'primary',
        ];
        break;

      case 6:
        $from_types = implode(', ',$this->fromType);
        drupal_set_message($this->t('Are you sure you want to convert all selected entities of type <em>@from_type</em> to type <em>@to_type</em>?',
                             [
                               '@from_type' => $from_types,
                               '@to_type' => $this->toType,
                             ]), 'warning');
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Convert'),
          '#button_type' => 'primary',
        ];
        break;
    }
    drupal_set_message($this->t('This module is experimental. PLEASE do not use on production databases without prior testing and a complete database dump.'), 'warning');
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $submit_label,
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    switch ($this->step) {
      case 1:
        if (empty(array_filter($form_state->getValues()['convert_bundles_from']))) {
          $form_state->setErrorByName('convert_bundles_from', $this->t('No bundles selected.'));
        }
        break;

      default:
        // TODO - validate other steps.
        break;
    }
  }

}
