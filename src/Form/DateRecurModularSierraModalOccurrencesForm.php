<?php

declare(strict_types = 1);

namespace Drupal\date_recur_modular\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\date_recur\DateRecurHelper;
use Drupal\date_recur_modular\DateRecurModularUtilityTrait;
use Drupal\date_recur_modular\DateRecurModularWidgetFieldsTrait;
use Drupal\date_recur_modular\DateRecurModularWidgetOptions;
use Drupal\date_recur_modular\Plugin\Field\FieldWidget\DateRecurModularSierraWidget;
use RRule\RSet;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generate a form to excluding occurrences, designed for display in modal.
 */
class DateRecurModularSierraModalOccurrencesForm extends FormBase {

  use DateRecurModularWidgetFieldsTrait;
  use DateRecurModularUtilityTrait;

  /**
   * The PrivateTempStore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  protected const UTC_FORMAT = 'Ymd\THis\Z';

  /**
   * Constructs a new DateRecurModularSierraModalForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   A config factory for retrieving required config objects.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The PrivateTempStore factory.
   */
  public function __construct(ConfigFactoryInterface $configFactory, PrivateTempStoreFactory $tempStoreFactory) {
    $this->configFactory = $configFactory;
    $this->tempStoreFactory = $tempStoreFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('tempstore.private')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'date_recur_modular_sierra_exclude_modal';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'date_recur_modular/date_recur_modular_sierra_widget_modal_form';
    $form['#attached']['library'][] = 'core/drupal.ajax';
    $form['#theme'] = 'date_recur_modular_sierra_widget_modal_form';

    $collection = $this->tempStoreFactory
      ->get(DateRecurModularSierraWidget::COLLECTION_MODAL_STATE);

    $rrule = $collection->get(DateRecurModularSierraWidget::COLLECTION_MODAL_STATE_KEY);
    $form['original_string'] = [
      '#type' => 'value',
      '#value' => $rrule,
    ];

    $dtStartString = $collection->get(DateRecurModularSierraWidget::COLLECTION_MODAL_STATE_DTSTART);

    if (!empty($dtStartString)) {
      $dtStart = \DateTime::createFromFormat(DateRecurModularSierraWidget::COLLECTION_MODAL_STATE_DTSTART_FORMAT, $dtStartString);
    }
    else {
      $dtStart = new \DateTime();
    }

    $parts = [];
    $rule1 = NULL;
    if (isset($rrule)) {
      try {
        $helper = DateRecurHelper::create($rrule, $dtStart);
        $rules = $helper->getRules();
        $rule1 = count($rules) > 0 ? reset($rules) : NULL;
        $parts = $rule1 ? $rule1->getParts() : [];
      }
      catch (\Exception $e) {
      }
    }


    ////////////////////////////////////////////////////////////////////////////////////
    ///
    // docs.
    $rset = new \RRule\RSet();
    foreach ($helper->getRules() as $rule) {
      $rset->addRRule($rule->getParts());
    }

    /** @var \DateTime[] $excluded */
    $excludes = $helper->getExcluded();
    sort($excludes);

    $dateFormat = 'r';
    $limit = 1024;
    $limitDate = new \DateTime('1 Jan 2020');
    $occurrences = [];
    $matchedExcludes = [];
    $unmatchedExcludes = [];
    $iteration = 0;
    foreach ($rset as $occurrenceDate) {
      if ($iteration > $limit || $limitDate < $occurrenceDate) {
        break;
      }

      $occurrences[$iteration] = [
        'date' => $occurrenceDate,
        'excluded' => FALSE,
      ];

      foreach ($excludes as $k => $exDate) {
        if ($exDate < $occurrenceDate) {
          $unmatchedExcludes[] = $exDate;
          unset($excludes[$k]);
        }
        elseif ($exDate == $occurrenceDate) {
          $matchedExcludes[] = $exDate;
          $occurrences[$iteration]['excluded'] = TRUE;
          unset($excludes[$k]);
        }
      }
      $iteration++;
    }

    // Merge in remaining excludes.
    $excludes = array_filter($excludes, function (\DateTime $exDate) use ($limitDate): bool {
      // Remove excludes that are out of range.
      return $exDate <= $limitDate;
    });
    $unmatchedExcludes = array_merge($excludes, $unmatchedExcludes);

    if (count($unmatchedExcludes)) {
      $form['invalid_excludes'] = [
        '#type' => 'details',
        '#title' => $this->formatPlural(count($unmatchedExcludes), '@count invalid exclude', '@count invalid excludes'),
      ];
      $form['invalid_excludes']['help'] = [
        '#type' => 'inline_template',
        '#template' => '<p>{{ message }}</p>',
        '#context' => [
          'message' => $this->formatPlural(count($unmatchedExcludes), 'This invalid excluded occurrence will be automatically removed.', 'These invalid excluded occurrences will be automatically removed.'),
        ],
      ];
      $form['invalid_excludes']['table'] = [
        '#type' => 'table',
        '#header' => [
          'date' => $this->t('Date'),
        ],
      ];
      $form['invalid_excludes']['table']['#rows'] = array_map(function (\DateTime $date) use ($dateFormat): array {
        return [
          'date' => $date->format($dateFormat),
        ];
      }, $unmatchedExcludes);
    }

    $form['occurrences'] = [
      '#type' => 'details',
      '#title' => $this->t('Occurrences'),
      '#open' => count($occurrences) > 0,
    ];

    $form['occurrences']['help'] = [
      '#type' => 'inline_template',
      '#template' => '<p>{{ message }}</p>',
      '#context' => [
        'message' => $this->t('This table shows a selection of occurrences. Occurrences may be removed individually.'),
      ],
    ];
    $form['occurrences']['table'] = [
      '#type' => 'table',
      '#header' => [
        'exclude' => $this->t('Exclude'),
        'date' => $this->t('Date'),
      ],
    ];
    $form['occurrences']['table']['#rows'] = array_map(function (array $occurrence) use ($dateFormat): array {
      /** @var \DateTime $date */
      /** @var bool $excluded */
      ['date' => $date, 'excluded' => $excluded] = $occurrence;
      $row = [];
      $row['exclude']['data'] = [
        '#type' => 'checkbox',
        // @todo change format.
        '#return_value' => $date->format('r'),
        '#name' => time() + mt_rand(),
        '#checked' => $excluded,
      ];
      $row['date']['data']['#markup'] = $date->format($dateFormat);
      return $row;
    }, $occurrences);

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Done'),
      '#button_type' => 'primary',
      '#ajax' => [
        'event' => 'click',
        // Need 'url' and 'options' for this submission button to use this
        // controller not the caller.
        'url' => Url::fromRoute('date_recur_modular_widget.sierra_modal_exclude_form'),
        'options' => [
          'query' => [
            FormBuilderInterface::AJAX_FORM_REQUEST => TRUE,
          ],
        ],
        'callback' => [$this, 'ajaxSubmitForm'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function ajaxSubmitForm(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    if ($form_state->getErrors()) {
      // Inspired by \Drupal\form_api_example\Form\ModalForm::ajaxSubmitForm.
      $form['status_messages'] = [
        '#type' => 'status_messages',
      ];
      // Open the form again as a modal.
      return $response->addCommand(new OpenModalDialogCommand(
        $this->t('Errors'),
        $form,
        ['width' => '575']
      ));
    }



    $collection = $this->tempStoreFactory
      ->get(DateRecurModularSierraWidget::COLLECTION_MODAL_STATE);
//    $collection->set(DateRecurModularSierraWidget::COLLECTION_MODAL_STATE_KEY, implode("\n", $lines));

    $refreshBtnName = sprintf('[name="%s"]', $collection->get(DateRecurModularSierraWidget::COLLECTION_MODAL_STATE_REFRESH_BUTTON));
    $response
      ->addCommand(new CloseDialogCommand())
      ->addCommand(new InvokeCommand($refreshBtnName, 'trigger', ['click']));

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    return new AjaxResponse();
  }

}
