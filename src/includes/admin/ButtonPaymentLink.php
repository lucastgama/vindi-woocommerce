<?php

namespace VindiPaymentGateways;

use WC_Subscriptions_Product;

class ButtonPaymentLink
{
    public function __construct()
    {
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'button_link_payment'], 20, 4);
    }

    public function button_link_payment($order)
    {
        $template_path = WP_PLUGIN_DIR . '/vindi-payment-gateway/src/templates/admin-payment-button.html.php';

        if (!$template_path) {
            return;
        }

        $order_data = $this->get_order_data($order);

        if ($order) {
            $item = $order_data['has_item'];
            $sub = $order_data['has_subscription'];
            $status = $order_data['order_status'];
            $link = $order_data['link_payment'];
            $shop =  $order_data['urlShopSubscription'];
            $type = get_post_type($order->get_id());
            $created = $order->get_created_via();
            $parent = $order_data['parent'];
            $disable = $this->should_disable($created, $sub, $item, $order);
            $hasClient = $order->get_customer_id();
            $variables = compact('item', 'sub', 'status', 'link', 'shop', 'type', 'created', 'parent', 'disable', 'hasClient');
            $this->include_template_with_variables($template_path, $variables);
        }
    }

    private function should_disable($created, $has_sub, $has_item, $order)
    {
        $posttype = get_post_type();
        $hasClient = $order->get_customer_id();
        if ($posttype == 'shop_order' &&  $hasClient) {
            if ($has_item) {
                if ($has_sub && $created == "admin") {
                    return false;
                }
                return true;
            }
        }
        if ($posttype == 'shop_subscription' &&  $hasClient) {
            if ($has_item) {
                if ($has_sub && $created == "admin") {
                    return true;
                }
                return false;
            }
        }
    }

    private function get_order_data($order)
    {
        $order_data = [
            'has_item' => false,
            'has_subscription' => false,
            'order_status' => $order->get_status(),
            'link_payment' => null,
            'urlAdmin' => get_admin_url(),
            'urlShopSubscription' => null,
            'parent' => false
        ];
        if (count($order->get_items()) > 0) {
            $order_data['has_subscription'] = $this->has_subscription($order);
            if ($order->get_checkout_payment_url()) {
                $order_data['link_payment'] = $this->build_payment_link($order, $order->get_payment_method());
            }
            $order_data = $this->handle_shop_subscription($order, $order_data);
            $order_data['order_status'] = $order->get_status();
            $order_data['has_item'] = true;
            $order_data['urlShopSubscription'] = "{$order_data['urlAdmin']}post-new.php?post_type=shop_subscription";
        }

        return $order_data;
    }

    private function handle_shop_subscription($order, $order_data)
    {
        if (get_post_type($order->get_id()) == 'shop_subscription') {
            $parent_order = $order->get_parent();
            if ($parent_order) {
                $parent_order_id = $parent_order->get_id();
                $order = wc_get_order($parent_order_id);
                $order_data['link_payment'] = $this->build_payment_link($order, $order->get_payment_method());
                $order_data['has_subscription'] = true;
                $order_data['parent'] = true;
            }
        }

        return $order_data;
    }

    public function has_subscription($order)
    {
        $subscriptions_product = new WC_Subscriptions_Product();
        $order_items = $order->get_items();
        foreach ($order_items as $order_item) {
            if ($subscriptions_product->is_subscription($order_item->get_product_id())) {
                return true;
            }
        }
        return false;
    }

    /*
    * Build the payment link (Dummy function for illustration).
    * @param WC_Order $order The order object.
    * @param string $gateway The payment gateway.
    * @return string The payment link.
    */
    public function build_payment_link($order, $gateway)
    {
        $url = wc_get_checkout_url();
        $gateway = $gateway ? "&vindi-gateway={$gateway}" : '';
        $orderId = $order->get_id();
        $orderKey = $order->get_order_key();

        return "{$url}order-pay/{$orderId}/?pay_for_order=true&key={$orderKey}&vindi-payment-link=true{$gateway}";
    }

    private function include_template_with_variables($template_path, $variables)
    {
        extract($variables);
        include $template_path;
    }
}
