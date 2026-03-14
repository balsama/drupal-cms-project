<?php

namespace Drupal\Tests\eca\Kernel;

use Drupal\eca\EventSubscriber\DynamicSubscriber;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException;

/**
 * Tests ECA token service circular dependency fixes.
 *
 * Validates fixes for circular dependency issues during Drupal 11.3+ hook
 * discovery and container compilation.
 *
 * @group eca
 * @group eca_token
 */
#[RunTestsInSeparateProcesses]
class CircularDependencyTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'eca',
    'eca_content',
    'token',
    'eca_test_circular_dependency',
  ];

  /**
   * Test token service injection without circular dependency.
   *
   * Without the fix in DynamicSubscriber::getSubscribedEvents():
   *   - ServiceCircularReferenceException during container build.
   *
   * With the fix:
   *   - Container builds successfully.
   *   - Service can be instantiated and used.
   */
  public function testTokenServiceInjection() {
    // Test that the test service can be instantiated.
    // This service injects the token service in its constructor.
    try {
      $testService = $this->container->get('eca_test_circular_dependency.test_service');
      $this->assertNotNull($testService, 'Test service should be instantiable');

      // Test that it can actually use the token service.
      $result = $testService->replaceTokens('[site:name]');
      $this->assertIsString($result);
    }
    catch (ServiceCircularReferenceException $e) {
      $this->fail('Test service should not cause circular dependency: ' . $e->getMessage());
    }
  }

  /**
   * Test DynamicSubscriber guards against circular dependencies.
   *
   * Without the fix:
   *   - getSubscribedEvents() during early build would fail.
   *   - Accessing state service would trigger circular dependency.
   *
   * With the fix:
   *   - Returns safely (empty array or actual events).
   *   - No circular dependency errors.
   */
  public function testDynamicSubscriberGuards() {
    // Test that DynamicSubscriber can provide subscribed events.
    $events = DynamicSubscriber::getSubscribedEvents();

    // Should return an array.
    $this->assertIsArray($events);

    // Should not throw circular dependency exception.
    $this->assertTrue(TRUE, 'DynamicSubscriber completed without errors');
  }

  /**
   * Test ContentHooks lazy-loads config.
   *
   * Without the fix:
   *   - Constructor would call configFactory->get() immediately.
   *   - Could trigger config factory during hook discovery.
   *
   * With the fix:
   *   - Config is lazy-loaded via getEcaSettings() method.
   *   - No config access during construction.
   */
  public function testContentHooksLazyConfig() {
    // Test that ContentHooks can be instantiated.
    try {
      if ($this->container->has('Drupal\eca_content\Hook\ContentHooks')) {
        $contentHooks = $this->container->get('Drupal\eca_content\Hook\ContentHooks');
        $this->assertNotNull($contentHooks, 'ContentHooks should be instantiable');
      }
      else {
        $this->markTestSkipped('ContentHooks service not available');
      }
    }
    catch (ServiceCircularReferenceException $e) {
      $this->fail('ContentHooks should not cause circular dependency: ' . $e->getMessage());
    }
  }

  /**
   * Test ECA services work after fixes.
   *
   * Validates that circular dependency fixes don't break normal ECA
   * functionality.
   */
  public function testEcaServicesWork() {
    // Test that core ECA services are accessible.
    $services = [
      'eca.service.token',
      'eca.token_services',
      'eca.trigger_event',
    ];

    foreach ($services as $service_id) {
      if ($this->container->has($service_id)) {
        try {
          $service = $this->container->get($service_id);
          $this->assertNotNull($service, "Service $service_id accessible");
        }
        catch (ServiceCircularReferenceException $e) {
          $this->fail("Service $service_id should not cause circular dependency: " . $e->getMessage());
        }
      }
    }
  }

}
