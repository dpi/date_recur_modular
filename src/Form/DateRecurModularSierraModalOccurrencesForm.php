<?php

declare(strict_types = 1);

namespace Drupal\date_recur_modular\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\date_recur\DateRecurHelper;
use Drupal\date_recur\DateRecurRuleInterface;
use Drupal\date_recur_modular\DateRecurModularUtilityTrait;
use Drupal\date_recur_modular\DateRecurModularWidgetFieldsTrait;
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
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a new DateRecurModularSierraModalForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   A config factory for retrieving required config objects.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The PrivateTempStore factory.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   */
  public function __construct(ConfigFactoryInterface $configFactory, PrivateTempStoreFactory $tempStoreFactory, DateFormatterInterface $dateFormatter) {
    $this->configFactory = $configFactory;
    $this->tempStoreFactory = $tempStoreFactory;
    $this->dateFormatter = $dateFormatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('tempstore.private'),
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'date_recur_modular_sierra_occurrences_modal';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $multiplier = $form_state->get('occurrence_multiplier');
    if (!isset($multiplier)) {
      $form_state->set('occurrence_multiplier', 1);
      $multiplier = 1;
    }

    // @todo show interpreted rule at top so u dont have to switch out/reopen custom modal of the popout to remember what the rule was
    $form['#attached']['library'][] = 'date_recur_modular/date_recur_modular_sierra_widget_modal_form';
    $form['#attached']['library'][] = 'core/drupal.ajax';
    $form['#theme'] = 'date_recur_modular_sierra_widget_modal_form';

    $collection = $this->tempStoreFactory
      ->get(DateRecurModularSierraWidget::COLLECTION_MODAL_STATE);

    $rrule = $collection->get(DateRecurModularSierraWidget::COLLECTION_MODAL_STATE_KEY);

    /** @var string $dateFormat */
    $dateFormatId = $collection->get(DateRecurModularSierraWidget::COLLECTION_MODAL_DATE_FORMAT);

    $form['original_string'] = [
      '#type' => 'value',
      '#value' => $rrule,
    ];

    $dtStartString = $collection->get(DateRecurModularSierraWidget::COLLECTION_MODAL_STATE_DTSTART);

    if (!empty($dtStartString)) {
      $dtStart = \DateTime::createFromFormat(DateRecurModularSierraWidget::COLLECTION_MODAL_STATE_DTSTART_FORMAT, $dtStartString);
    }
    else {
      // Use current date if there is no valid starting date from handoff.
      $dtStart = new \DateTime();
    }
    $form['date_start'] = [
      '#type' => 'value',
      '#value' => $dtStart,
    ];

    if (isset($rrule)) {
      try {
        $helper = DateRecurHelper::create($rrule, $dtStart);
      }
      catch (\Exception $e) {
      }
    }

    // Rebuild using Rset becauae we want to be able to iterate over occurrences
    // without considering any existing EXDATEs.
    $rset = new RSet();
    /** @var \DateTime[] $excluded */
    $excludes = [];
    if (isset($helper)) {
      foreach ($helper->getRules() as $rule) {
        $rset->addRRule($rule->getParts());
      }
      $excludes = $helper->getExcluded();
    }
    sort($excludes);

    // Initial limit is 1024, with 128 per page thereafter, with an absolute
    // maximum of 64000. Limit prevents performance issues and abuse.
    $limit = min(1024 + (128 * ($multiplier - 1)), 64000);
    $limitDate = (new \DateTime('23:59:59 last day of december this year'))
      ->modify(sprintf('+%d months', ($multiplier - 1) * 4));
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

      // After each occurrence evaluate if there were any excludes that fit
      // between this occurrence and last occurrence.
      foreach ($excludes as $k => $exDate) {
        if ($exDate < $occurrenceDate) {
          // Occurrence was between this and last occurrence, so likely no
          // longer matches against the RRULE.
          // Its done progessively like this instead of comparing occurrences to
          // EXDATEs as some EXDATEs may fall outside of the date/count limits.
          $unmatchedExcludes[] = $exDate;
          unset($excludes[$k]);
        }
        elseif ($exDate == $occurrenceDate) {
          // Occurrence matches an exclude date exactly.
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
      $form['invalid_excludes']['table']['#rows'] = array_map(function (\DateTime $date) use ($dateFormatId): array {
        return [
          'date' => $this->dateFormatter->format($date->getTimestamp(), $dateFormatId),
        ];
      }, $unmatchedExcludes);
    }

    $form['occurrences'] = [
      '#type' => 'details',
      '#title' => $this->t('Occurrences'),
      '#open' => TRUE,
    ];

    $form['occurrences']['help'] = [
      '#type' => 'inline_template',
      '#template' => '<p>{{ message }}</p>',
      '#context' => [
        'message' => $this->t('This table shows a selection of occurrences. Occurrences may be removed individually. Times are displayed in <em>@time_zone</em> time zone.', [
          '@time_zone' => $dtStart->getTimezone()->getName(),
        ]),
      ],
    ];

    $form['occurrences']['table'] = [
      '#type' => 'table',
      '#header' => [
        'exclude' => $this->t('Exclude'),
        'date' => $this->t('Date'),
      ],
      '#empty' => $this->t('There are no occurrences.'),
      '#prefix' => '<div id="occurrences-table">',
      '#suffix' => '</div>',
    ];

    $i = 0;
    foreach ($occurrences as $occurrence) {
      /** @var \DateTime $date */
      /** @var bool $excluded */
      ['date' => $date, 'excluded' => $excluded] = $occurrence;
      $date->setTimezone($dtStart->getTimezone());
      $row = [];
      $row['exclude'] = [
        '#type' => 'checkbox',
        '#return_value' => $i,
        '#default_value' => $excluded,
      ];
      $row['date']['#markup'] = $this->dateFormatter->format($date->getTimestamp(), $dateFormatId);
      $row['#date_object'] = $date;
      $form['occurrences']['table'][$i] = $row;
      $i++;
    }

    $form['show_more'] = [
      '#type' => 'submit',
      '#value' => $this->t('Show more'),
      '#ajax' => [
        'event' => 'click',
        // Need 'url' and 'options' for this submission button to use this
        // controller not the caller.
        'url' => Url::fromRoute('date_recur_modular_widget.sierra_modal_occurrences_form'),
        'options' => [
          'query' => [
            FormBuilderInterface::AJAX_FORM_REQUEST => TRUE,
          ],
        ],
        'callback' => [$this, 'ajaxShowMore'],
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Done'),
      '#button_type' => 'primary',
      '#ajax' => [
        'event' => 'click',
        // Need 'url' and 'options' for this submission button to use this
        // controller not the caller.
        'url' => Url::fromRoute('date_recur_modular_widget.sierra_modal_occurrences_form'),
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
  public function ajaxShowMore(array &$form, FormStateInterface $form_state): AjaxResponse {
    $form_state->setRebuild();

    $multiplier = $form_state->get('occurrence_multiplier');
    $form_state->set('occurrence_multiplier', $multiplier + 1);

    $response = new AjaxResponse();
    $form = \Drupal::formBuilder()->rebuildForm($this->getFormId(), $form_state, $form);
    $response->addCommand(new OpenModalDialogCommand(
      $this->t('Occurrences'),
      $form,
      ['width' => '575']
    ));
    return $response;
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

    $originalString = $form_state->getValue('original_string');
    /** @var \DateTime $dtStart */
    $dtStart = $form_state->getValue('date_start');

    try {
      $helper = DateRecurHelper::create($originalString, $dtStart);
    }
    catch (\Exception $e) {
    }

    // Rebuild original set without EXDATES.
    $rset = new RSet();
    if (isset($helper)) {
      array_walk($helper->getRules(), function (DateRecurRuleInterface $rule) use ($rset) {
        $parts = $rule->getParts();
        unset($parts['DTSTART']);
        $rset->addRRule($parts);
      });
    }

    foreach ($form_state->getValue('table') as $i => $row) {
      if ($row['exclude'] !== 0) {
        $date = $form['occurrences']['table'][$i]['#date_object'];
        $rset->addExDate($date);
      }
    }

    $lines = [];
    foreach ($rset->getRRules() as $rule) {
      /** @var \RRule\RRule $rule */
      $lines[] = 'RRULE:' . $rule->rfcString(FALSE);
    }

    $utc = new \DateTimeZone('UTC');
    $exDates = array_map(function (\DateTime $exDate) use ($utc) {
      $exDate->setTimezone($utc);
      return $exDate->format(static::UTC_FORMAT);
    }, $rset->getExDates());
    if (count($exDates) > 0) {
      $lines[] = 'EXDATE:' . implode(',', $exDates);
    }

    $collection = $this->tempStoreFactory->get(DateRecurModularSierraWidget::COLLECTION_MODAL_STATE);
    $collection->set(DateRecurModularSierraWidget::COLLECTION_MODAL_STATE_KEY, implode("\n", $lines));

    $refreshBtnName = sprintf('[name="%s"]', $collection->get(DateRecurModularSierraWidget::COLLECTION_MODAL_STATE_REFRESH_BUTTON));
    $response
      ->addCommand(new CloseDialogCommand())
      // Transfers new lines to widget.
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
