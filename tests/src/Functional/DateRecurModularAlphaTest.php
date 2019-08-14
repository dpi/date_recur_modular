<?php

declare(strict_types = 1);

namespace Drupal\Tests\date_recur_modular\Functional;

use Drupal\date_recur_entity_test\Entity\DrEntityTest;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Tests Alpha Widget.
 *
 * @group date_recur_modular_widget
 * @coversDefaultClass \Drupal\date_recur_modular\Plugin\Field\FieldWidget\DateRecurModularAlphaWidget
 */
class DateRecurModularAlphaTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'date_recur_modular',
    'date_recur_entity_test',
    'entity_test',
    'datetime',
    'datetime_range',
    'date_recur',
    'field',
    'user',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // @todo replace in 8.8: See 2835616.
    $display = \entity_get_form_display('dr_entity_test', 'dr_entity_test', 'default');
    $component = $display->getComponent('dr');
    $component['region'] = 'content';
    $component['type'] = 'date_recur_modular_alpha';
    $component['settings'] = [];
    $display->setComponent('dr', $component);
    $display->save();

    $user = $this->drupalCreateUser(['administer entity_test content']);
    $user->timezone = 'Asia/Singapore';
    $user->save();
    $this->drupalLogin($user);
  }

  /**
   * Tests field widget input is converted to appropriate database values.
   *
   * @param array $values
   *   Array of form fields to submit.
   * @param array $expected
   *   Array of expected field normalized values.
   *
   * @dataProvider providerTestWidget
   */
  public function testWidget(array $values, array $expected): void {
    $entity = DrEntityTest::create();
    $entity->save();
    $this->drupalGet($entity->toUrl('edit-form'));

    $this->drupalPostForm(NULL, $values, 'Save');
    $this->assertSession()->pageTextContains('has been updated.');

    $entity = DrEntityTest::load($entity->id());
    $this->assertEquals($expected, $entity->dr[0]->getValue());
  }

  /**
   * Data provider for testWidget()
   *
   * @return array
   *   Data for testing.
   */
  public function providerTestWidget(): array {
    $data = [];

    $data['once'] = [
      [
        'dr[0][mode]' => 'once',
        'dr[0][start][date]' => '04/14/2015',
        'dr[0][start][time]' => '09:00:00am',
        'dr[0][end][date]' => '04/14/2015',
        'dr[0][end][time]' => '05:00:00pm',
      ],
      [
        'value' => '2015-04-14T01:00:00',
        'end_value' => '2015-04-14T09:00:00',
        'rrule' => NULL,
        'infinite' => FALSE,
        'timezone' => 'Asia/Singapore',
      ],
    ];

    $data['multi'] = [
      [
        'dr[0][mode]' => 'multiday',
        'dr[0][daily_count]' => 3,
        'dr[0][start][date]' => '04/14/2015',
        'dr[0][start][time]' => '09:00:00am',
        'dr[0][end][date]' => '04/14/2015',
        'dr[0][end][time]' => '5:00:00pm',
      ],
      [
        'value' => '2015-04-14T01:00:00',
        'end_value' => '2015-04-14T09:00:00',
        'rrule' => 'FREQ=DAILY;INTERVAL=1;COUNT=3',
        'infinite' => FALSE,
        'timezone' => 'Asia/Singapore',
      ],
    ];

    $data['weekly'] = [
      [
        'dr[0][mode]' => 'weekly',
        'dr[0][weekdays][MO]' => TRUE,
        'dr[0][weekdays][WE]' => TRUE,
        'dr[0][weekdays][FR]' => TRUE,
        'dr[0][start][date]' => '04/14/2015',
        'dr[0][start][time]' => '09:00:00am',
        'dr[0][end][date]' => '04/14/2015',
        'dr[0][end][time]' => '05:00:00pm',
      ],
      [
        'value' => '2015-04-14T01:00:00',
        'end_value' => '2015-04-14T09:00:00',
        'rrule' => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,WE,FR',
        'infinite' => TRUE,
        'timezone' => 'Asia/Singapore',
      ],
    ];

    $data['fortnightly'] = [
      [
        'dr[0][mode]' => 'fortnightly',
        'dr[0][weekdays][MO]' => TRUE,
        'dr[0][weekdays][WE]' => TRUE,
        'dr[0][weekdays][FR]' => TRUE,
        'dr[0][start][date]' => '04/14/2015',
        'dr[0][start][time]' => '09:00:00am',
        'dr[0][end][date]' => '04/14/2015',
        'dr[0][end][time]' => '05:00:00pm',
      ],
      [
        'value' => '2015-04-14T01:00:00',
        'end_value' => '2015-04-14T09:00:00',
        'rrule' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE,FR',
        'infinite' => TRUE,
        'timezone' => 'Asia/Singapore',
      ],
    ];

    // First Friday of the month.
    $data['monthly 1 ordinal 1 weekday'] = [
      [
        'dr[0][mode]' => 'monthly',
        'dr[0][start][date]' => '04/14/2015',
        'dr[0][start][time]' => '09:00:00am',
        'dr[0][end][date]' => '04/14/2015',
        'dr[0][end][time]' => '05:00:00pm',
        // Set weekday first, ordinals will appear after it.
        'dr[0][weekdays][FR]' => TRUE,
        'dr[0][ordinals][1]' => TRUE,
      ],
      [
        'value' => '2015-04-14T01:00:00',
        'end_value' => '2015-04-14T09:00:00',
        'rrule' => 'FREQ=MONTHLY;INTERVAL=1;BYDAY=FR;BYSETPOS=1',
        'infinite' => TRUE,
        'timezone' => 'Asia/Singapore',
      ],
    ];

    // First Thursday and Friday of the month.
    $data['monthly 1 ordinal 2 weekday'] = [
      [
        'dr[0][mode]' => 'monthly',
        'dr[0][start][date]' => '04/14/2015',
        'dr[0][start][time]' => '09:00:00am',
        'dr[0][end][date]' => '04/14/2015',
        'dr[0][end][time]' => '05:00:00pm',
        'dr[0][weekdays][TH]' => TRUE,
        'dr[0][weekdays][FR]' => TRUE,
        'dr[0][ordinals][1]' => TRUE,
      ],
      [
        'value' => '2015-04-14T01:00:00',
        'end_value' => '2015-04-14T09:00:00',
        'rrule' => 'FREQ=MONTHLY;INTERVAL=1;BYDAY=TH,FR;BYSETPOS=1,2',
        'infinite' => TRUE,
        'timezone' => 'Asia/Singapore',
      ],
    ];

    // First and second Friday of the month.
    $data['monthly 1,2 ordinal 1 weekday'] = [
      [
        'dr[0][mode]' => 'monthly',
        'dr[0][start][date]' => '04/14/2015',
        'dr[0][start][time]' => '09:00:00am',
        'dr[0][end][date]' => '04/14/2015',
        'dr[0][end][time]' => '05:00:00pm',
        'dr[0][weekdays][FR]' => TRUE,
        'dr[0][ordinals][1]' => TRUE,
        'dr[0][ordinals][2]' => TRUE,
      ],
      [
        'value' => '2015-04-14T01:00:00',
        'end_value' => '2015-04-14T09:00:00',
        'rrule' => 'FREQ=MONTHLY;INTERVAL=1;BYDAY=FR;BYSETPOS=1,2',
        'infinite' => TRUE,
        'timezone' => 'Asia/Singapore',
      ],
    ];

    // First and second Thursday and Friday of the month.
    $data['monthly 1,2 ordinal 2 weekday'] = [
      [
        'dr[0][mode]' => 'monthly',
        'dr[0][start][date]' => '04/14/2015',
        'dr[0][start][time]' => '09:00:00am',
        'dr[0][end][date]' => '04/14/2015',
        'dr[0][end][time]' => '05:00:00pm',
        'dr[0][weekdays][TH]' => TRUE,
        'dr[0][weekdays][FR]' => TRUE,
        'dr[0][ordinals][1]' => TRUE,
        'dr[0][ordinals][2]' => TRUE,
      ],
      [
        'value' => '2015-04-14T01:00:00',
        'end_value' => '2015-04-14T09:00:00',
        'rrule' => 'FREQ=MONTHLY;INTERVAL=1;BYDAY=TH,FR;BYSETPOS=1,2,3,4',
        'infinite' => TRUE,
        'timezone' => 'Asia/Singapore',
      ],
    ];

    // Last Thursday of the month.
    $data['monthly -1 ordinal 1 weekday'] = [
      [
        'dr[0][mode]' => 'monthly',
        'dr[0][start][date]' => '04/14/2015',
        'dr[0][start][time]' => '09:00:00am',
        'dr[0][end][date]' => '04/14/2015',
        'dr[0][end][time]' => '05:00:00pm',
        'dr[0][weekdays][TH]' => TRUE,
        'dr[0][ordinals][-1]' => TRUE,
      ],
      [
        'value' => '2015-04-14T01:00:00',
        'end_value' => '2015-04-14T09:00:00',
        'rrule' => 'FREQ=MONTHLY;INTERVAL=1;BYDAY=TH;BYSETPOS=-1',
        'infinite' => TRUE,
        'timezone' => 'Asia/Singapore',
      ],
    ];

    // Last Thursday and Friday of the month.
    $data['monthly -1 ordinal 2 weekday'] = [
      [
        'dr[0][mode]' => 'monthly',
        'dr[0][start][date]' => '04/14/2015',
        'dr[0][start][time]' => '09:00:00am',
        'dr[0][end][date]' => '04/14/2015',
        'dr[0][end][time]' => '05:00:00pm',
        'dr[0][weekdays][TH]' => TRUE,
        'dr[0][weekdays][FR]' => TRUE,
        'dr[0][ordinals][-1]' => TRUE,
      ],
      [
        'value' => '2015-04-14T01:00:00',
        'end_value' => '2015-04-14T09:00:00',
        'rrule' => 'FREQ=MONTHLY;INTERVAL=1;BYDAY=TH,FR;BYSETPOS=-2,-1',
        'infinite' => TRUE,
        'timezone' => 'Asia/Singapore',
      ],
    ];

    // Second to last Thursday of the month.
    $data['monthly -2 ordinal 1 weekday'] = [
      [
        'dr[0][mode]' => 'monthly',
        'dr[0][start][date]' => '04/14/2015',
        'dr[0][start][time]' => '09:00:00am',
        'dr[0][end][date]' => '04/14/2015',
        'dr[0][end][time]' => '05:00:00pm',
        'dr[0][weekdays][TH]' => TRUE,
        'dr[0][ordinals][-2]' => TRUE,
      ],
      [
        'value' => '2015-04-14T01:00:00',
        'end_value' => '2015-04-14T09:00:00',
        'rrule' => 'FREQ=MONTHLY;INTERVAL=1;BYDAY=TH;BYSETPOS=-2',
        'infinite' => TRUE,
        'timezone' => 'Asia/Singapore',
      ],
    ];

    // Second to last Thursday and Friday of the month.
    $data['monthly -4,-3 ordinal 2 weekday'] = [
      [
        'dr[0][mode]' => 'monthly',
        'dr[0][start][date]' => '04/14/2015',
        'dr[0][start][time]' => '09:00:00am',
        'dr[0][end][date]' => '04/14/2015',
        'dr[0][end][time]' => '05:00:00pm',
        'dr[0][weekdays][TH]' => TRUE,
        'dr[0][weekdays][FR]' => TRUE,
        'dr[0][ordinals][-2]' => TRUE,
      ],
      [
        'value' => '2015-04-14T01:00:00',
        'end_value' => '2015-04-14T09:00:00',
        'rrule' => 'FREQ=MONTHLY;INTERVAL=1;BYDAY=TH,FR;BYSETPOS=-4,-3',
        'infinite' => TRUE,
        'timezone' => 'Asia/Singapore',
      ],
    ];

    // Last and Second to last Thursday and Friday of the month.
    $data['monthly -4,-3-2,-1 ordinal 2 weekday'] = [
      [
        'dr[0][mode]' => 'monthly',
        'dr[0][start][date]' => '04/14/2015',
        'dr[0][start][time]' => '09:00:00am',
        'dr[0][end][date]' => '04/14/2015',
        'dr[0][end][time]' => '05:00:00pm',
        'dr[0][weekdays][TH]' => TRUE,
        'dr[0][weekdays][FR]' => TRUE,
        'dr[0][ordinals][-1]' => TRUE,
        'dr[0][ordinals][-2]' => TRUE,
      ],
      [
        'value' => '2015-04-14T01:00:00',
        'end_value' => '2015-04-14T09:00:00',
        'rrule' => 'FREQ=MONTHLY;INTERVAL=1;BYDAY=TH,FR;BYSETPOS=-4,-3,-2,-1',
        'infinite' => TRUE,
        'timezone' => 'Asia/Singapore',
      ],
    ];

    // Combination second and second to last Thursday and Friday of the month.
    $data['monthly -4,-3,3,4 ordinal 2 weekday'] = [
      [
        'dr[0][mode]' => 'monthly',
        'dr[0][start][date]' => '04/14/2015',
        'dr[0][start][time]' => '09:00:00am',
        'dr[0][end][date]' => '04/14/2015',
        'dr[0][end][time]' => '05:00:00pm',
        'dr[0][weekdays][TH]' => TRUE,
        'dr[0][weekdays][FR]' => TRUE,
        'dr[0][ordinals][2]' => TRUE,
        'dr[0][ordinals][-2]' => TRUE,
      ],
      [
        'value' => '2015-04-14T01:00:00',
        'end_value' => '2015-04-14T09:00:00',
        'rrule' => 'FREQ=MONTHLY;INTERVAL=1;BYDAY=TH,FR;BYSETPOS=-4,-3,3,4',
        'infinite' => TRUE,
        'timezone' => 'Asia/Singapore',
      ],
    ];

    return $data;
  }

}
