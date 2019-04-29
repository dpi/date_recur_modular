<?php

declare(strict_types = 1);

namespace Drupal\Tests\date_recur_modular\Functional;

use Drupal\date_recur_entity_test\Entity\DrEntityTest;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Tests Oscar Widget.
 *
 * @group date_recur_modular_widget
 * @coversDefaultClass \Drupal\date_recur_modular\Plugin\Field\FieldWidget\DateRecurModularOscarWidget
 */
class DateRecurModularOscarTest extends WebDriverTestBase {

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
    $component['type'] = 'date_recur_modular_oscar';
    $component['settings'] = [];
    $display->setComponent('dr', $component);
    $display->save();

    $user = $this->drupalCreateUser(['administer entity_test content']);
    $user->timezone = 'Asia/Singapore';
    $user->save();
    $this->drupalLogin($user);
  }

  /**
   * Tests all-day toggle visibility.
   */
  public function testAllDayToggle(): void {
    $entity = DrEntityTest::create();
    $entity->save();

    $this->drupalGet($entity->toUrl('edit-form'));

    // By default toggle is enabled, so it should be visible.
    // Assert each container exists before checking the all-day element.
    $this->assertSession()->elementExists('css', '.parts--times');
    // Must have all-day toggle.
    $this->assertSession()->elementExists('css', '.parts--times .parts--is-all-day');

    // @todo replace in 8.8: See 2835616.
    $display = \entity_get_form_display('dr_entity_test', 'dr_entity_test', 'default');
    $component = $display->getComponent('dr');
    $component['settings']['all_day_toggle'] = FALSE;
    $display->setComponent('dr', $component);
    $display->save();

    $this->drupalGet($entity->toUrl('edit-form'));
    $this->assertSession()->elementExists('css', '.parts--times');
    $this->assertSession()->elementNotExists('css', '.parts--times .parts--is-all-day');
  }

}
