<?php

namespace VindiPaymentGateways;

/**
 * Vindi Payment Pix Automático Gateway class.
 *
 * Extended by individual payment gateways to handle payments.
 *
 * @class   VindiPixAutomaticoGateway
 * @extends VindiPaymentGateway
 */
class VindiPixAutomaticoGateway extends VindiPaymentGateway
{
    /**
     * @var VindiSettings
     */
    public $vindi_settings;

    /**
     * @var VindiControllers
     */
    public $controllers;

    /**
     * Constructor for the gateway.
     */
    public function __construct(VindiSettings $vindi_settings, VindiControllers $controllers)
    {
        $this->id                   = 'vindi-pix-automatico';
        $this->icon                 = apply_filters('vindi_woocommerce_pix_automatico_icon', '');
        $this->method_title         = __('Vindi - Pix Automático', VINDI);
        $this->method_description   = __('Pagamentos recorrentes via Pix Automático (Open Finance). Disponível apenas para produtos de assinatura/recorrência.', VINDI);
        $this->has_fields           = false;
        $this->supports             = array(
            'subscriptions',
            'subscription_cancellation',
            'subscription_reactivation',
            'subscription_suspension',
            'subscription_amount_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'subscription_date_changes',
            'multiple_subscriptions',
            'pre-orders'
        );
        $this->init_form_fields();
        $this->init_settings();
        add_action('woocommerce_thankyou_' . $this->id, array(&$this, 'thank_you_page'));
        add_action('woocommerce_view_order', array(&$this, 'show_consent_status'), -10, 1);
        add_action('woocommerce_subscription_details_after_subscription_table', array(&$this, 'show_subscription_consent_status'), 10, 1);
        parent::__construct($vindi_settings, $controllers);
    }

    /**
     * Should return payment type for payment processing.
     * @return string
     */
    public function type()
    {
        return 'pix_automatic';
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Habilitar/Desabilitar', VINDI),
                'label'       => __('Habilitar pagamento por Pix Automático com Vindi', VINDI),
                'type'        => 'checkbox',
                'default'     => 'no',
            ),
            'title'   => array(
                'title'       => __('Título', VINDI),
                'type'        => 'text',
                'description' => __('Título que o cliente verá durante o processo de pagamento.', VINDI),
                'default'     => __('Pix Automático', VINDI),
            )
        );
    }

    /**
     * Render the payment fields on checkout.
     * Pix Automático não tem campos — apenas texto explicativo.
     */
    public function payment_fields()
    {
        $user_country = $this->get_country_code();

        if (empty($user_country)) {
            _e('Selecione o País para visualizar as formas de pagamento.', VINDI);
            return;
        }

        if ($this->is_single_order()) {
            _e('Pix Automático está disponível apenas para produtos de assinatura/recorrência.', VINDI);
            return;
        }

        $this->vindi_settings->get_template('pix-automatico-checkout.html.php', []);
    }

    /**
     * Thank you page — exibe o link de consentimento imediatamente.
     */
    public function thank_you_page($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }

        $consent_url = $order->get_meta('vindi_pix_automatic_consent_url', true);
        $this->vindi_settings->get_template(
            'pix-automatico-thankyou.html.php',
            compact('consent_url', 'order')
        );
    }

    /**
     * View order — exibe o status do consentimento.
     * A assinatura WC é a fonte do status (é onde os webhooks consent_* gravam);
     * o meta do pedido fica como fallback.
     */
    public function show_consent_status($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }

        $subscriptions = function_exists('wcs_get_subscriptions_for_order')
            ? wcs_get_subscriptions_for_order($order, array('order_type' => 'any'))
            : array();
        $source = !empty($subscriptions) ? reset($subscriptions) : $order;

        $this->render_consent_status($source, $order);
    }

    /**
     * Minha Conta > Assinaturas > Visualizar — exibe o status do consentimento.
     */
    public function show_subscription_consent_status($subscription)
    {
        if (!$subscription || $subscription->get_payment_method() !== $this->id) {
            return;
        }

        $this->render_consent_status($subscription, $subscription->get_parent());
    }

    private function render_consent_status($source, $order)
    {
        $this->maybe_refresh_consent_url($source);

        $consent_url     = $source->get_meta('vindi_pix_automatic_consent_url', true);
        $consent_status  = $source->get_meta('vindi_pix_automatic_consent_status', true);
        $consent_expired = $this->is_consent_url_expired($source);
        $this->vindi_settings->get_template(
            'pix-automatico-status.html.php',
            compact('consent_url', 'consent_status', 'consent_expired', 'order')
        );
    }

    /**
     * O link de consentimento expira 60 minutos após a geração (guia Vindi, seção 2.3).
     * Status in_progress é ignorado: o cliente já está no app do banco.
     */
    private function is_consent_url_expired($source)
    {
        $status = $source->get_meta('vindi_pix_automatic_consent_status', true);
        if ('expired' === $status) {
            return true;
        }
        if (!in_array($status, array('', 'created'), true)) {
            return false;
        }

        $created_at = (int) $source->get_meta('vindi_pix_automatic_consent_url_created_at', true);
        return $created_at > 0 && (time() - $created_at) > HOUR_IN_SECONDS;
    }

    /**
     * Link vencido: consulta a assinatura na Vindi e, se houver um link novo, substitui o
     * anterior na assinatura e no pedido pai. Consulta limitada a uma a cada 5 minutos.
     */
    private function maybe_refresh_consent_url($source)
    {
        if (!$this->is_consent_url_expired($source)) {
            return;
        }

        $vindi_subscription_id = $source->get_meta('vindi_subscription_id', true);
        $throttle_key = 'vindi_pix_consent_refresh_' . $source->get_id();
        if (empty($vindi_subscription_id) || get_transient($throttle_key)) {
            return;
        }
        set_transient($throttle_key, 1, 5 * MINUTE_IN_SECONDS);

        $vindi_subscription = $this->vindi_settings->routes->getSubscription($vindi_subscription_id);
        $new_url = $vindi_subscription['pix_automatic_consent_url'] ?? '';
        if (empty($new_url) || $new_url === $source->get_meta('vindi_pix_automatic_consent_url', true)) {
            return;
        }

        $targets = array($source);
        if (is_a($source, 'WC_Subscription') && $source->get_parent()) {
            $targets[] = $source->get_parent();
        }
        foreach ($targets as $target) {
            $target->update_meta_data('vindi_pix_automatic_consent_url', $new_url);
            $target->update_meta_data('vindi_pix_automatic_consent_status', 'created');
            $target->update_meta_data('vindi_pix_automatic_consent_url_created_at', time());
            $target->save();
        }
    }
}