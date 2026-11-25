<?php

namespace VindiPaymentGateways;

use WC_Customer;

class CustomerController
{

  /**
   * @var VindiSettings
   */
  private $vindi_settings;

  /**
   * @var VindiRoutes
   */
  private $routes;

  function __construct(VindiSettings $vindi_settings)
  {

    $this->vindi_settings = $vindi_settings;
    $this->routes = $vindi_settings->routes;

    // Fires immediately after a new user is registered.
    add_action('user_register', array($this, 'create'), 10, 1);

    // Fires immediately after an existing user is updated.
    add_action('woocommerce_customer_save_address', array($this, 'update'), 10, 1);
    add_action('woocommerce_save_account_details', array($this, 'update'), 10, 1);
    add_action('delete_user', array($this, 'delete'), 10, 1);
  }

  /**
   * When a new user is created within the WP, it is reflected in the Vindi.
   *
   * @since 1.0.0
   * @version 1.0.0
   */
  function create($user_id, $order = null)
  {

    $customer = new WC_Customer($user_id);

    $user = $customer->get_data();

    $name = (!$user['first_name']) ? $user['display_name'] : $user['first_name'] . ' ' . $user['last_name'];
    $notes = null;
    $cpf_or_cnpj = null;
    $metadata = null;

    $phones = $this->build_phones($customer);


    if ($order && method_exists($order, 'needs_payment')) {
      $metadata = array();
      if ('2' === $order->get_meta('_billing_persontype')) {
        // Pessoa jurídica
        $name = $order->get_billing_company();
        $cpf_or_cnpj = $order->get_meta('_billing_cnpj');
        $notes = sprintf('Nome: %s %s', $order->get_billing_first_name(), $order->get_billing_last_name());

        if ($this->vindi_settings->send_nfe_information()) {
          $metadata['inscricao_estadual'] = $order->get_meta('_billing_ie');
        }
      } else {
        // Pessoa física
        $cpf_or_cnpj = $order->get_meta('_billing_cpf');
        $notes = '';

        if ($this->vindi_settings->send_nfe_information()) {
          $metadata['carteira_de_identidade'] = $order->get_meta('_billing_rg');
        }
      }
    }

    $createdUser = $this->routes->createCustomer(
      array(
        'name' => $name,
        'email' => ($user['email']) ? $user['email'] : '',
        'code' => 'WC-USER-' . $user['id'],
        'address' => array(
          'street' => ($customer->get_billing_address_1()) ? $customer->get_billing_address_1() : '',
          'number' => ($customer->get_meta('billing_number')) ? $customer->get_meta('billing_number') : '',
          'additional_details' => ($customer->get_billing_address_2()) ?  $customer->get_billing_address_2() : '',
          'zipcode' => ($customer->get_billing_postcode()) ? $customer->get_billing_postcode() : '',
          'neighborhood' => ($customer->get_meta('billing_neighborhood')) ? $customer->get_meta('billing_neighborhood') : '',
          'city' => ($customer->get_billing_city()) ? $customer->get_billing_city() : '',
          'state' => ($customer->get_billing_state()) ? $customer->get_billing_state() : '',
          'country' => ($customer->get_billing_country()) ? $customer->get_billing_country() : ''
        ),
        'phones' => $phones,
        'registry_code' => $cpf_or_cnpj ? $cpf_or_cnpj : '',
        'notes' => $notes ? $notes : '',
        'metadata' => !empty($metadata) ? $metadata : '',
      )
    );

    if (isset($createdUser['id']) && $createdUser['id']) {
      update_user_meta($user_id, 'vindi_customer_id', $createdUser['id']);
    }

    return $createdUser;
  }


  /**
   * When a user is updated within the WP, it is reflected in the Vindi.
   *
   * @since 1.0.0
   * @version 1.0.0
   */

  function update($user_id, $order = null)
  {
    $vindi_customer_id = get_user_meta($user_id, 'vindi_customer_id', true);

    if (!empty($vindi_customer_id)) {
      $vindiUser = $this->routes->findCustomerById($vindi_customer_id);
    }
    if (empty($vindi_customer_id) || empty($vindiUser)) {
      $vindiUser = $this->routes->findCustomerByCode($user_id);
      if (!empty($vindiUser['id'])) {
        $vindi_customer_id = $vindiUser['id'];
        update_user_meta($user_id, 'vindi_customer_id', $vindi_customer_id);
      }
    }

    if (empty($vindi_customer_id) || empty($vindiUser)) {
      $user = get_user_by('id', $user_id);
      if ($user) {
        $email = $user->user_email;
        $vindiUser = $this->routes->findCustomerByEmail($email);
        if (!empty($vindiUser['id'])) {
          $vindi_customer_id = $vindiUser['id'];
          update_user_meta($user_id, 'vindi_customer_id', $vindi_customer_id);
        }
      }
    }

    if (empty($vindi_customer_id)) {
      return $this->create($user_id, $order);
    }
    if (!$vindiUser) {
      return $this->create($user_id);
    }
    $customer = new WC_Customer($user_id);
    $user = $customer->get_data();
    $phones = $this->build_phones($customer, $vindiUser['phones'] ?? []);
    $name = (!$user['first_name']) ? $user['display_name'] : $user['first_name'] . ' ' . $user['last_name'];
    $notes = null;
    $cpf_or_cnpj = null;
    $metadata = null;
    if ($order && method_exists($order, 'needs_payment')) {
      $metadata = array();
      if ('2' === $order->get_meta('_billing_persontype')) {
        $name = $order->get_billing_company();
        $cpf_or_cnpj = $order->get_meta('_billing_cnpj');
        $notes = sprintf('Nome: %s %s', $order->get_billing_first_name(), $order->get_billing_last_name());

        if ($this->vindi_settings->send_nfe_information()) {
          $metadata['inscricao_estadual'] = $order->get_meta('_billing_ie');
        }
      }
      if ('2' !== $order->get_meta('_billing_persontype')) {
        $cpf_or_cnpj = $order->get_meta('_billing_cpf');
        $notes = '';

        if ($this->vindi_settings->send_nfe_information()) {
          $metadata['carteira_de_identidade'] = $order->get_meta('_billing_rg');
        }
      }
    }
    $updatedUser = $this->routes->updateCustomer(
      $vindi_customer_id,
      array(
        'name' => $name,
        'email' => ($user['email']) ? $user['email'] : '',
        'code' => 'WC-USER-' . $user['id'],
        'address' => array(
          'street' => ($customer->get_billing_address_1()) ? $customer->get_billing_address_1() : '',
          'number' => ($customer->get_meta('billing_number')) ? $customer->get_meta('billing_number') : '',
          'additional_details' => ($customer->get_billing_address_2()) ?  $customer->get_billing_address_2() : '',
          'zipcode' => ($customer->get_billing_postcode()) ? $customer->get_billing_postcode() : '',
          'neighborhood' => ($customer->get_meta('billing_neighborhood')) ? $customer->get_meta('billing_neighborhood') : '',
          'city' => ($customer->get_billing_city()) ? $customer->get_billing_city() : '',
          'state' => ($customer->get_billing_state()) ? $customer->get_billing_state() : '',
          'country' => ($customer->get_billing_country()) ? $customer->get_billing_country() : ''
        ),
        'phones' => $phones,
        'registry_code' => $cpf_or_cnpj ? $cpf_or_cnpj : '',
        'notes' => $notes ? $notes : '',
        'metadata' => !empty($metadata) ? $metadata : '',
      )
    );
    return $updatedUser;
  }

  /**
   * Build the `phones` payload, reusing the ids of the phones the customer
   * already has in Vindi.
   *
   * Vindi appends every phone sent without an `id`, and it may change the
   * `phone_type` we send (a cellphone saved as billing phone comes back as
   * "mobile"). Matching only by type left the phone without id on every
   * checkout, piling up records until the "no máximo 3 registros" error.
   * So the match is by number first and by type as a fallback.
   *
   * @param WC_Customer $customer
   * @param array       $vindi_phones Phones returned by Vindi for the customer.
   *
   * @return array
   */
  private function build_phones($customer, $vindi_phones = [])
  {
    $candidates = array(
      'mobile'   => $customer->get_meta('billing_cellphone'),
      'landline' => $customer->get_billing_phone(),
    );

    $phones = [];
    $used_ids = [];
    foreach ($candidates as $type => $raw) {
      if (!$raw) {
        continue;
      }

      $number = preg_replace('/\D+/', '', '55' . $raw);
      if (in_array($number, array_column($phones, 'number'), true)) {
        continue;
      }

      $phone = array(
        'phone_type' => $type,
        'number'     => $number,
      );

      $id = $this->find_vindi_phone_id($vindi_phones, 'number', $number, $used_ids)
        ?: $this->find_vindi_phone_id($vindi_phones, 'phone_type', $type, $used_ids);
      if ($id) {
        $phone['id'] = $id;
        $used_ids[] = $id;
      }

      $phones[] = $phone;
    }

    return $phones;
  }

  private function find_vindi_phone_id($vindi_phones, $field, $value, $used_ids)
  {
    foreach ((array) $vindi_phones as $vindi_phone) {
      if (empty($vindi_phone['id']) || in_array($vindi_phone['id'], $used_ids, true)) {
        continue;
      }
      $current = (string) ($vindi_phone[$field] ?? '');
      if ('number' === $field) {
        $current = preg_replace('/\D+/', '', $current);
      }
      if ($current === (string) $value) {
        return $vindi_phone['id'];
      }
    }

    return null;
  }

  /**
   * When a user is deleted within the WP, it is reflected in the Vindi.
   *
   * @since 1.0.0
   * @version 1.0.0
   */

  function delete($user_id)
  {

    $vindi_customer_id = get_user_meta($user_id, 'vindi_customer_id', true);

    // Check meta Vindi ID
    if (empty($vindi_customer_id)) {

      return;
    }

    // Check user exists in Vindi
    $vindiUser = $this->routes->findCustomerById($vindi_customer_id);
    if (!$vindiUser) {

      return;
    }

    // Delete customer profile
    $deletedUser = $this->routes->deleteCustomer(
      $vindi_customer_id
    );

    return $deletedUser;
  }
}
