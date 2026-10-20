<?php

namespace VindiPaymentGateways;

class VindiSubscriptionStatusHandler
{
    /**
     * @var VindiSettings
     */
    private $vindi_settings;
    
    /**
     * @var VindiRoutes
     */
    private $routes;

    /**
     * IDs das assinaturas WC com renovação em andamento.
     *
     * @var array
     */
    private static $renewals_in_progress = array();

    public function __construct(VindiSettings $vindi_settings)
    {
        $this->vindi_settings = $vindi_settings;
        $this->routes = $vindi_settings->routes;

        add_action('woocommerce_scheduled_subscription_payment', array(
            __CLASS__, 'start_renewal',
        ), 0);

        add_action('woocommerce_scheduled_subscription_payment', array(
            __CLASS__, 'finish_renewal',
        ), PHP_INT_MAX);

        add_action('woocommerce_order_action_wcs_create_pending_renewal', array(
            __CLASS__, 'start_renewal',
        ), 0);

        add_action('woocommerce_order_action_wcs_create_pending_renewal', array(
            __CLASS__, 'finish_renewal',
        ), PHP_INT_MAX);

        add_action('woocommerce_subscription_status_cancelled', array(
            &$this, 'cancelled_status',
        ));

        add_action('woocommerce_subscription_status_updated', array(
            &$this, 'filter_pre_status',
        ), 1, 3);

        add_action('woocommerce_order_fully_refunded', array(
            &$this, 'order_fully_refunded',
        ));

        add_action('woocommerce_order_status_cancelled', array(
            &$this, 'order_canceled',
        ));
    }

    /**
     * @param WC_Subscription $wc_subscription
     * @param string          $new_status
     * @param string          $old_status
     */
    public function filter_pre_status($wc_subscription, $new_status, $old_status)
    {
        switch ($new_status) {
            case 'on-hold':
                $this->handle_on_hold_status($wc_subscription);
                break;
            case 'active':
                $this->active_status($wc_subscription, $old_status);
                break;
            case 'cancelled':
                $this->cancelled_status($wc_subscription);
                break;
            case 'pending-cancel':
                if (!$this->vindi_settings->dependencies->is_wc_memberships_active()) {
                    $wc_subscription->update_status('pending-cancel');
                    $this->cancelled_status($wc_subscription);
                }
                break;
        }
    }

    /**
     * Trata mudanças para o status 'on-hold'.
     *
     * Durante a renovação o WooCommerce Subscriptions coloca a assinatura
     * em on-hold até o pedido de renovação ser pago. Propagar esse estado
     * cancelaria a assinatura na Vindi (DELETE) e o webhook
     * subscription_canceled poderia chegar depois da reativação. Nesse caso
     * o status não é propagado; a suspensão manual continua sendo enviada.
     *
     * @param WC_Subscription $wc_subscription
     */
    private function handle_on_hold_status($wc_subscription)
    {
        if (!$this->vindi_settings->get_synchronism_status()) {
            return;
        }

        $vindi_subscription_id = $this->get_vindi_subscription_id($wc_subscription);
        $reason = $this->get_awaiting_payment_reason($wc_subscription);

        if (null === $reason) {
            $this->vindi_settings->logger->log(sprintf(
                __('Status on-hold manual para a assinatura WC #%s (Vindi #%s). Suspendendo na Vindi.', VINDI),
                $wc_subscription->get_id(),
                $vindi_subscription_id ?: 'N/A'
            ));
            $this->suspend_status($wc_subscription);
            return;
        }

        $this->vindi_settings->logger->log(sprintf(
            __('Status on-hold aguardando pagamento para a assinatura WC #%s (Vindi #%s): %s. ' .
               'Não propagando para a Vindi para evitar o cancelamento durante a renovação.', VINDI),
            $wc_subscription->get_id(),
            $vindi_subscription_id ?: 'N/A',
            $reason
        ));
    }

    /**
     * Marca o início de uma renovação da assinatura WC.
     *
     * @param int|WC_Subscription $subscription ID ou objeto da assinatura.
     */
    public static function start_renewal($subscription)
    {
        self::$renewals_in_progress[self::subscription_key($subscription)] = true;
    }

    /**
     * Marca o fim de uma renovação da assinatura WC.
     *
     * @param int|WC_Subscription $subscription ID ou objeto da assinatura.
     */
    public static function finish_renewal($subscription)
    {
        unset(self::$renewals_in_progress[self::subscription_key($subscription)]);
    }

    /**
     * @param int|WC_Subscription $subscription
     * @return int
     */
    private static function subscription_key($subscription)
    {
        return (int) (is_object($subscription) ? $subscription->get_id() : $subscription);
    }

    /**
     * Indica se o on-hold decorre de uma renovação em andamento ou de um
     * pedido aguardando pagamento (renovação falha ou fim de trial), e não
     * de uma suspensão manual.
     *
     * @param WC_Subscription $wc_subscription
     * @return bool
     */
    public function is_awaiting_payment_on_hold($wc_subscription)
    {
        return null !== $this->get_awaiting_payment_reason($wc_subscription);
    }

    /**
     * Motivo pelo qual o on-hold aguarda pagamento, ou null quando é manual.
     *
     * @param WC_Subscription $wc_subscription
     * @return string|null
     */
    private function get_awaiting_payment_reason($wc_subscription)
    {
        if (isset(self::$renewals_in_progress[self::subscription_key($wc_subscription)])) {
            return 'renovação em andamento';
        }

        $last_order = $wc_subscription->get_last_order('all', array('parent', 'renewal'));

        if (is_object($last_order)
            && method_exists($last_order, 'has_status')
            && $last_order->has_status(array('pending', 'failed'))) {
            return sprintf(
                'último pedido #%s com status %s',
                method_exists($last_order, 'get_id') ? $last_order->get_id() : '?',
                method_exists($last_order, 'get_status') ? $last_order->get_status() : '?'
            );
        }

        return null;
    }

    /**
     * @param WC_Subscription $wc_subscription
     */
    public function suspend_status($wc_subscription)
    {
        $subscription_id = $this->get_vindi_subscription_id($wc_subscription);
        if ($this->vindi_settings->get_synchronism_status()) {
            $this->routes->suspendSubscription($subscription_id);
        }
    }

    /**
     * @param WC_Subscription $wc_subscription
     */
    public function cancelled_status($wc_subscription)
    {
        $subscription_id = $this->get_vindi_subscription_id($wc_subscription);
        if ($this->routes->isSubscriptionActive($subscription_id)) {
            $this->routes->suspendSubscription($subscription_id, true);
        }
    }

    /**
     * @param WC_Subscription $wc_subscription
     */
    public function active_status($wc_subscription, $old_status)
    {
        if ('pending' == $old_status) {
            return;
        }

        $subscription_id = $this->get_vindi_subscription_id($wc_subscription);
        if ($this->vindi_settings->get_synchronism_status()
            && !$this->routes->isSubscriptionActive($subscription_id)) {
            $this->routes->activateSubscription($subscription_id);
        }
    }

    /**
     * @param WC_Subscription $wc_subscription
     */
    public function get_wc_subscription_id($subscription_id)
    {

        $subscription = wcs_get_subscription($subscription_id);
        if ($subscription) {
            $vindi_subscription_id = $subscription->get_meta('vindi_subscription_id', true);
            if (!empty($vindi_subscription_id)) {
                return $vindi_subscription_id;
            }
            return $subscription->get_meta('vindi_wc_subscription_id', true);
        }
    }

    public function get_vindi_subscription_id($wc_subscription)
    {
        $subscription_id = method_exists($wc_subscription, 'get_id')
        ? $wc_subscription->get_id()
        : $wc_subscription->id;
        return $this->get_wc_subscription_id($subscription_id);
    }

    /**
     * @param WC_Order $order
     */
    public function order_fully_refunded($order)
    {
        if (!is_object($order)) {
            $order = wc_get_order($order);
        }

        if (wcs_order_contains_subscription($order, array('parent', 'renewal'))) {
            $subscriptions = wcs_get_subscriptions_for_order(wcs_get_objects_property($order, 'id'), array('order_type' => array('parent', 'renewal')));
            foreach ($subscriptions as $subscription) {
                $latest_order = $subscription->get_last_order();

                if (wcs_get_objects_property($order, 'id') == $latest_order && $subscription->can_be_updated_to('cancelled')) {
                    // translators: $1: opening link tag, $2: order number, $3: closing link tag
                    $subscription->update_status(
                        'cancelled',
                        wp_kses(sprintf(
                            __('A assinatura foi cancelada pelo pedido reembolsado %1$s#%2$s%3$s.', VINDI),
                            sprintf('<a href="%s">', esc_url(wcs_get_edit_post_link(wcs_get_objects_property($order, 'id')))),
                            $order->get_order_number(),
                            '</a>'
                        ), array('a' => array('href' => true))));
                }
            }
        }
    }

    /**
     * @param WC_Order $order
     */
    public function order_canceled($order)
    {
        if (!is_object($order)) {
            $order = wc_get_order($order);
        }

        $vindi_order = $order->get_meta('vindi_order', true);

        if (!is_array($vindi_order)) {
            return;
        }
        $single_payment_bill_id = 0;

        foreach ($vindi_order as $key => $item) {
            if (isset($vindi_order[$key]['bill']['status']) && $vindi_order[$key]['bill']['status'] !== 'canceled') {
                $single_payment_bill_id = $vindi_order[$key]['bill']['id'];
                $vindi_order[$key]['bill']['status'] = 'canceled';
            }
        }
        
        $order->update_meta_data('vindi_order', $vindi_order);
        $order->save();

        if ($single_payment_bill_id) {
            $this->routes->deleteBill($single_payment_bill_id);
        }
    }
}
