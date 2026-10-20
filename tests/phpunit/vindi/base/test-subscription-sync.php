<?php

include_once VINDI_PATH . 'src/includes/admin/Settings.php';
include_once VINDI_PATH . 'src/routes/RoutesApi.php';
include_once VINDI_PATH . 'src/services/Logger.php';
include_once VINDI_PATH . 'src/services/Webhooks.php';
include_once VINDI_PATH . 'src/services/WebhooksHelpers.php';
include_once VINDI_PATH . 'src/utils/SubscriptionStatusHandler.php';
include_once VINDI_PATH . 'src/utils/PaymentProcessor.php';

use VindiPaymentGateways\VindiRoutes;
use VindiPaymentGateways\VindiSettings;
use VindiPaymentGateways\VindiWebhooks;
use VindiPaymentGateways\VindiSubscriptionStatusHandler;
use VindiPaymentGateways\VindiPaymentProcessor;
use VindiPaymentGateways\WebhooksHelpers;

/**
 * O ambiente de testes não carrega o WooCommerce Subscriptions, então
 * as assinaturas são registradas aqui e servidas pelo stub abaixo.
 */
$GLOBALS['vindi_test_subscriptions'] = array();

if (!function_exists('wcs_get_subscription')) {
  function wcs_get_subscription($id)
  {
    if (is_object($id)) {
      return $id;
    }

    return isset($GLOBALS['vindi_test_subscriptions'][(int) $id])
      ? $GLOBALS['vindi_test_subscriptions'][(int) $id]
      : false;
  }
}

/**
 * Interrompe o wp_send_json() sem ser capturado pelos catch (\Exception)
 * dos handlers de webhook.
 */
class Vindi_Test_Json_Sent extends \Error
{
}

class Vindi_Test_Fake_Order
{
  private $status;

  public function __construct($status)
  {
    $this->status = $status;
  }

  public function has_status($status)
  {
    return in_array($this->status, (array) $status, true);
  }
}

class Vindi_Test_Fake_Subscription
{
  public $id;
  public $status;
  public $meta = array();
  public $last_order = false;
  public $trial_end = 0;
  public $status_updates = array();

  public function __construct($id, $status = 'active')
  {
    $this->id = $id;
    $this->status = $status;
  }

  public function get_id()
  {
    return $this->id;
  }

  public function get_status()
  {
    return $this->status;
  }

  public function has_status($status)
  {
    return in_array($this->status, (array) $status, true);
  }

  public function update_status($status, $note = '')
  {
    $this->status = $status;
    $this->status_updates[] = $status;
  }

  public function get_meta($key, $single = true)
  {
    return isset($this->meta[$key]) ? $this->meta[$key] : '';
  }

  public function get_last_order($return_fields = 'ids', $order_types = array('parent', 'renewal'))
  {
    return $this->last_order;
  }

  public function get_time($key)
  {
    return 'trial_end' === $key ? $this->trial_end : 0;
  }

  public function get_parent_id()
  {
    return 0;
  }
}

class Vindi_Test_Fake_Logger
{
  public $messages = array();

  public function log($message)
  {
    $this->messages[] = $message;
  }
}

class Vindi_Test_Fake_Dependencies
{
  public $memberships_active = false;

  public function is_wc_memberships_active()
  {
    return $this->memberships_active;
  }
}

class Vindi_Test_Subscription_Sync extends Vindi_Test_Base
{
  const WC_SUBSCRIPTION_ID = 1001;
  const VINDI_SUBSCRIPTION_ID = 5005;

  /** @var Vindi_Test_Fake_Subscription */
  private $subscription;

  /** @var \PHPUnit\Framework\MockObject\MockObject */
  private $routes;

  /** @var Vindi_Test_Fake_Dependencies */
  private $dependencies;

  /** @var VindiSettings */
  private $settings;

  public function setUp()
  {
    parent::setUp();

    $this->subscription = new Vindi_Test_Fake_Subscription(self::WC_SUBSCRIPTION_ID);
    $this->subscription->meta['vindi_subscription_id'] = self::VINDI_SUBSCRIPTION_ID;
    $GLOBALS['vindi_test_subscriptions'] = array(self::WC_SUBSCRIPTION_ID => $this->subscription);

    $this->routes = $this->createMock(VindiRoutes::class);
    $this->dependencies = new Vindi_Test_Fake_Dependencies();

    $this->settings = $this->createMock(VindiSettings::class);
    $this->settings->method('get_synchronism_status')->willReturn(true);
    $this->settings->routes = $this->routes;
    $this->settings->logger = new Vindi_Test_Fake_Logger();
    $this->settings->dependencies = $this->dependencies;

    add_filter('wp_doing_ajax', '__return_true');
    add_filter('wp_die_ajax_handler', array($this, 'get_die_handler'));
  }

  public function tearDown()
  {
    remove_filter('wp_doing_ajax', '__return_true');
    remove_filter('wp_die_ajax_handler', array($this, 'get_die_handler'));
    $GLOBALS['vindi_test_subscriptions'] = array();

    parent::tearDown();
  }

  public function get_die_handler()
  {
    return function () {
      throw new Vindi_Test_Json_Sent();
    };
  }

  /**
   * Executa um método privado do webhook e devolve o JSON enviado.
   */
  private function call_webhook($method, $argument, $helpers = null)
  {
    $webhooks = new VindiWebhooks($this->settings);
    if (null !== $helpers) {
      $property = new ReflectionProperty($webhooks, 'webhooksHelpers');
      $property->setAccessible(true);
      $property->setValue($webhooks, $helpers);
    }
    $reflection = new ReflectionMethod($webhooks, $method);
    $reflection->setAccessible(true);

    ob_start();
    try {
      $reflection->invoke($webhooks, $argument);
    } catch (Vindi_Test_Json_Sent $sent) {
      unset($sent);
    }

    return json_decode(ob_get_clean(), true);
  }

  private function canceled_payload()
  {
    return json_decode(json_encode(array(
      'subscription' => array(
        'id' => self::VINDI_SUBSCRIPTION_ID,
        'code' => 'WC-' . self::WC_SUBSCRIPTION_ID,
      ),
    )));
  }

  public function test_subscription_canceled_requests_retry_when_vindi_lookup_fails()
  {
    $this->routes->method('getSubscription')->willReturn(false);

    $response = $this->call_webhook('subscription_canceled', $this->canceled_payload());

    $this->assertEquals('Não foi possível consultar a assinatura na Vindi.', $response['mensagem']);
    $this->assertEmpty($this->subscription->status_updates);
  }

  public function test_subscription_canceled_is_ignored_when_vindi_is_not_canceled()
  {
    $this->routes->method('getSubscription')->willReturn(array('status' => 'active'));

    $response = $this->call_webhook('subscription_canceled', $this->canceled_payload());

    $this->assertEquals('Assinatura não está cancelada na Vindi — webhook ignorado.', $response['mensagem']);
    $this->assertEmpty($this->subscription->status_updates);
  }

  public function test_subscription_canceled_cancels_when_vindi_is_canceled()
  {
    $this->routes->expects($this->once())
      ->method('getSubscription')
      ->willReturn(array('status' => 'canceled'));
    $this->routes->method('hasPendingSubscriptionBills')->willReturn(false);

    $this->call_webhook('subscription_canceled', $this->canceled_payload());

    $this->assertEquals(array('cancelled'), $this->subscription->status_updates);
  }

  public function test_subscription_canceled_applies_pending_cancel_with_memberships()
  {
    $this->dependencies->memberships_active = true;
    $this->routes->expects($this->once())
      ->method('getSubscription')
      ->willReturn(array('status' => 'canceled'));
    $this->routes->method('hasPendingSubscriptionBills')->willReturn(false);

    $this->call_webhook('subscription_canceled', $this->canceled_payload());

    $this->assertEquals(array('pending-cancel'), $this->subscription->status_updates);
  }

  public function test_old_subscription_canceled_event_is_still_processed()
  {
    $this->routes->method('getSubscription')->willReturn(array('status' => 'canceled'));
    $this->routes->method('hasPendingSubscriptionBills')->willReturn(false);

    $body = json_decode(json_encode(array(
      'event' => array(
        'type' => 'subscription_canceled',
        'created_at' => gmdate('c', time() - 2 * HOUR_IN_SECONDS),
        'data' => $this->canceled_payload(),
      ),
    )));

    $this->call_webhook('process_event', $body);

    $this->assertEquals(array('cancelled'), $this->subscription->status_updates);
  }

  /**
   * O WCS coloca a assinatura em on-hold antes de criar o pedido de
   * renovação: nesse momento o último pedido ainda é o anterior, já pago.
   */
  public function test_on_hold_during_renewal_is_not_sent_to_vindi()
  {
    $this->subscription->last_order = new Vindi_Test_Fake_Order('completed');
    $this->routes->expects($this->never())->method('suspendSubscription');

    $handler = new VindiSubscriptionStatusHandler($this->settings);
    VindiSubscriptionStatusHandler::start_renewal(self::WC_SUBSCRIPTION_ID);
    try {
      $handler->filter_pre_status($this->subscription, 'on-hold', 'active');
    } finally {
      VindiSubscriptionStatusHandler::finish_renewal(self::WC_SUBSCRIPTION_ID);
    }
  }

  /**
   * "Criar pedido de renovação pendente" passa o objeto da assinatura.
   */
  public function test_on_hold_from_create_pending_renewal_is_not_sent_to_vindi()
  {
    $this->subscription->last_order = new Vindi_Test_Fake_Order('completed');
    $this->routes->expects($this->never())->method('suspendSubscription');

    $handler = new VindiSubscriptionStatusHandler($this->settings);
    VindiSubscriptionStatusHandler::start_renewal($this->subscription);
    try {
      $handler->filter_pre_status($this->subscription, 'on-hold', 'active');
    } finally {
      VindiSubscriptionStatusHandler::finish_renewal($this->subscription);
    }
  }

  public function test_subscription_reactivated_is_acknowledged_when_already_active()
  {
    $this->routes->method('hasPendingSubscriptionBills')->willReturn(false);

    $response = $this->call_webhook('subscription_reactivated', $this->canceled_payload());

    $this->assertEquals('A assinatura já está ativa.', $response['message']);
    $this->assertEmpty($this->subscription->status_updates);
  }

  public function test_manual_on_hold_after_renewal_suspends_subscription_on_vindi()
  {
    $this->subscription->last_order = new Vindi_Test_Fake_Order('completed');
    $this->routes->expects($this->once())->method('suspendSubscription');

    $handler = new VindiSubscriptionStatusHandler($this->settings);
    VindiSubscriptionStatusHandler::start_renewal(self::WC_SUBSCRIPTION_ID);
    VindiSubscriptionStatusHandler::finish_renewal(self::WC_SUBSCRIPTION_ID);

    $handler->filter_pre_status($this->subscription, 'on-hold', 'active');
  }

  public function test_on_hold_with_failed_renewal_order_is_not_sent_to_vindi()
  {
    $this->subscription->last_order = new Vindi_Test_Fake_Order('failed');
    $this->routes->expects($this->never())->method('suspendSubscription');

    $handler = new VindiSubscriptionStatusHandler($this->settings);
    $handler->filter_pre_status($this->subscription, 'on-hold', 'active');
  }

  public function test_manual_on_hold_suspends_subscription_on_vindi()
  {
    $this->subscription->last_order = new Vindi_Test_Fake_Order('completed');
    $this->routes->expects($this->once())
      ->method('suspendSubscription')
      ->with(self::VINDI_SUBSCRIPTION_ID);

    $handler = new VindiSubscriptionStatusHandler($this->settings);
    $handler->filter_pre_status($this->subscription, 'on-hold', 'active');
  }

  public function test_manual_on_hold_without_orders_suspends_subscription_on_vindi()
  {
    $this->subscription->last_order = false;
    $this->routes->expects($this->once())->method('suspendSubscription');

    $handler = new VindiSubscriptionStatusHandler($this->settings);
    $handler->filter_pre_status($this->subscription, 'on-hold', 'active');
  }

  public function test_trial_with_end_of_period_billing_is_reported_as_expected()
  {
    $this->subscription->trial_end = time() + DAY_IN_SECONDS;
    $this->subscription->meta['vindi_billing_trigger_type'] = 'end_of_period';

    $helpers = new WebhooksHelpers(new VindiWebhooks($this->settings));

    $this->assertSame(
      WebhooksHelpers::TRIAL_END_OF_PERIOD,
      $helpers->handle_trial_period('WC-' . self::WC_SUBSCRIPTION_ID)
    );
    $this->assertEmpty($this->subscription->status_updates);
  }

  /**
   * A primeira fatura chega via bill_created depois que o checkout já criou
   * o pedido do ciclo 1: não há nada a fazer e a Vindi não deve reenviar.
   */
  public function test_bill_created_for_existing_cycle_is_acknowledged()
  {
    $helpers = $this->createMock(WebhooksHelpers::class);
    $helpers->method('renew_infos_array')->willReturn(array('wc_subscription_id' => 'WC-' . self::WC_SUBSCRIPTION_ID));
    $helpers->method('handle_subscription_renewal')->willReturn(false);
    $helpers->method('handle_trial_period')->willReturn(false);

    $payload = json_decode(json_encode(array(
      'bill' => array(
        'id' => 1,
        'subscription' => array('id' => self::VINDI_SUBSCRIPTION_ID, 'code' => 'WC-' . self::WC_SUBSCRIPTION_ID),
      ),
    )));

    $response = $this->call_webhook('bill_created', $payload, $helpers);

    $this->assertEquals('Fatura já processada para este ciclo.', $response['message']);
  }

  public function test_fee_product_is_reused_when_update_fails()
  {
    $existing = array(
      'id' => 77,
      'code' => 'wc-fee',
      'pricing_schema' => array('schema_type' => 'per_unit'),
    );
    $this->routes->method('findProductByCode')->willReturn($existing);
    $this->routes->method('updateProduct')->willReturn(false);
    $this->routes->expects($this->never())->method('createProduct');

    $reflection = new ReflectionClass(VindiPaymentProcessor::class);
    $processor = $reflection->newInstanceWithoutConstructor();
    foreach (array('routes' => $this->routes, 'logger' => $this->settings->logger) as $name => $value) {
      $property = $reflection->getProperty($name);
      $property->setAccessible(true);
      $property->setValue($processor, $value);
    }

    $method = $reflection->getMethod('create_fee_product');
    $method->setAccessible(true);

    $this->assertSame($existing, $method->invoke($processor));
  }
}
