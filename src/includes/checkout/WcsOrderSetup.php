<?php

namespace VindiPaymentGateways;

class OrderSetup
{
    public function __construct()
    {
        add_filter('woocommerce_payment_gateways', [$this, 'restrict_payment_gateways_for_vindi_payment_link']);
    }


    public function restrict_payment_gateways_for_vindi_payment_link($available_gateways)
    {
        if ($this->is_vindi_payment_link()) {
            $allowed_gateways = $this->get_allowed_gateways();
            $available_gateways = $this->filter_gateways($available_gateways, $allowed_gateways);
        }

        return $available_gateways;
    }

    private function is_vindi_payment_link()
    {
        $isPaymentLink = filter_input(INPUT_GET, 'vindi-payment-link') ?? false;
        return is_admin() && $isPaymentLink;
    }

    private function get_allowed_gateways()
    {
        return [
            'vindi-bank-slip',
            'vindi-bolepix',
            'vindi-credit-card',
            'vindi-pix',
        ];
    }

    private function filter_gateways($available_gateways, $allowed_gateways)
    {
        return array_filter($available_gateways, function ($gateway_id) use ($allowed_gateways) {
            return in_array($gateway_id, $allowed_gateways);
        }, ARRAY_FILTER_USE_KEY);
    }
}
