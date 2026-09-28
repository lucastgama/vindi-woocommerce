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
        add_action('woocommerce_email_order_meta', array(&$this, 'add_consent_url_to_email'), 10, 3);
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

        $consent_url    = $order->get_meta('vindi_pix_automatic_consent_url', true);
        $consent_status = $order->get_meta('vindi_pix_automatic_consent_status', true);
        $this->vindi_settings->get_template(
            'pix-automatico-thankyou.html.php',
            compact('consent_url', 'consent_status', 'order')
        );
    }

    /**
     * View order — exibe o status do consentimento.
     */
    public function show_consent_status($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }

        $consent_url    = $order->get_meta('vindi_pix_automatic_consent_url', true);
        $consent_status = $order->get_meta('vindi_pix_automatic_consent_status', true);
        $this->vindi_settings->get_template(
            'pix-automatico-status.html.php',
            compact('consent_url', 'consent_status', 'order')
        );
    }

    /**
     * Injeta o botão de consentimento do Pix Automático nos e-mails do WooCommerce.
     * Mostra apenas em e-mails de pedidos que usaram Pix Automático e ainda não autorizaram.
     *
     * @param WC_Order $order         Pedido.
     * @param bool     $sent_to_admin Se o e-mail está sendo enviado para o admin.
     * @param bool     $plain_text    Se o e-mail é texto puro.
     */
    public function add_consent_url_to_email($order, $sent_to_admin, $plain_text)
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        if ($order->get_payment_method() !== $this->id) {
            return;
        }

        $consent_url    = $order->get_meta('vindi_pix_automatic_consent_url', true);
        $consent_status = $order->get_meta('vindi_pix_automatic_consent_status', true);

        // Só mostra se ainda não foi autorizado/rejeitado/expirado e há URL
        if (empty($consent_url) || in_array($consent_status, array('authorized', 'rejected', 'expired', 'revoked'), true)) {
            return;
        }

        if ($plain_text) {
            echo "\n" . esc_html__('Autorize o Pix Automático no seu banco para ativar sua assinatura:', VINDI) . "\n";
            echo esc_url($consent_url) . "\n\n";
            return;
        }

        ?>
        <div style="padding: 20px; border: 2px solid #0073aa; border-radius: 6px; margin: 20px 0; background: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
            <h3 style="margin-top: 0; color: #0073aa;">
                <?php _e('🔐 Autorize o Pix Automático no seu banco', VINDI); ?>
            </h3>
            <p style="font-size: 14px; line-height: 1.6; color: #333;">
                <?php _e('Para ativar sua assinatura, clique no botão abaixo e autorize o débito automático no aplicativo do seu banco. O link expira em 60 minutos.', VINDI); ?>
            </p>
            <p style="margin: 16px 0;">
                <a href="<?php echo esc_url($consent_url); ?>" target="_blank" rel="noopener" style="background: #0073aa; color: #ffffff; padding: 12px 24px; font-size: 15px; font-weight: 600; border-radius: 6px; text-decoration: none; display: inline-block;">
                    <?php _e('🔗 Autorizar Pix Automático no banco', VINDI); ?>
                </a>
            </p>
            <p style="font-size: 12px; color: #666; margin: 0;">
                <?php _e('Se o botão não funcionar, copie e cole este link no seu navegador:', VINDI); ?><br>
                <code style="font-size: 11px; word-break: break-all;"><?php echo esc_html($consent_url); ?></code>
            </p>
        </div>
        <?php
    }
}