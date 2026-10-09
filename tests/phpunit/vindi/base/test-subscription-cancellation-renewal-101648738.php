<?php
/**
 * Testes de regressão para o bug #101648738.
 *
 * Cobre:
 *   - Status `on-hold` no WooCommerce NÃO deve disparar DELETE na Vindi.
 *   - Webhook `subscription_canceled` com evento velho (>30min) deve ser descartado.
 *   - Webhook `subscription_canceled` quando a Vindi já reativou deve ser ignorado.
 *
 * Estes testes requerem o ambiente PHPUnit configurado
 * (vide docker-compose.yml + tests/bootstrap.php).
 *
 * Como rodar (quando o ambiente estiver disponível):
 *   docker-compose up -d
 *   docker-compose exec wordpress_phpunit phpunit -c /app/phpunit.xml --filter Subscription_Cancellation_Renewal_101648738
 *
 * @package VindiPaymentGateway
 */

/**
 * @group bug-101648738
 * @group webhook
 */
class Subscription_Cancellation_Renewal_101648738 extends WP_UnitTestCase {

    /**
     * Fixture: assinatura WC ativa, com meta vindi_subscription_id.
     *
     * @return array{0: WC_Subscription, 1: int}
     */
    private function create_fake_wc_subscription_with_vindi_meta(int $vindi_id = 51957755): array {
        $sub = wcs_create_subscription([
            'status' => 'active',
            'billing_period' => 'month',
            'billing_interval' => 1,
        ]);
        $sub->update_meta_data('vindi_subscription_id', (string) $vindi_id);
        $sub->save();
        return [$sub, $vindi_id];
    }

    /**
     * @test
     * Fix 1 — Causa raiz.
     *
     * Quando o WC muda o status da assinatura para `on-hold` (caso típico
     * do início de uma renovação automática do WCS), o plugin NÃO deve
     * disparar DELETE para a API da Vindi.
     */
    public function on_hold_nao_dispara_delete_na_vindi() {
        [$wc_sub, $vindi_id] = $this->create_fake_wc_subscription_with_vindi_meta();

        // Captura as chamadas HTTP feitas pelo plugin via filter wp_remote_post.
        $api_calls = [];
        $this->vindi_capture_api_calls($api_calls);

        // Dispara o hook exatamente como o WC Subscriptions faria.
        $settings = $this->build_fake_settings();
        $handler  = new VindiPaymentGateways\VindiSubscriptionStatusHandler($settings);

        $wc_sub->update_status('on-hold', 'Renovação iniciada');
        do_action('woocommerce_subscription_status_updated', $wc_sub, 'on-hold', 'active');

        // Nenhuma chamada DELETE deve ter sido feita.
        $delete_calls = array_filter($api_calls, function ($c) {
            return strtoupper($c['method'] ?? 'POST') === 'DELETE';
        });
        $this->assertCount(0, $delete_calls,
            'Fix 1 falhou: o plugin disparou DELETE ao entrar em on-hold. Chamadas: '
            . print_r($delete_calls, true));
    }

    /**
     * @test
     * Fix 2 — Idempotência por idade do evento.
     *
     * Um webhook `subscription_canceled` cujo `created_at` é mais antigo
     * que a janela configurada deve ser descartado com HTTP 200 (não 422),
     * sinalizando à Vindi para parar de reentregar.
     */
    public function webhook_subscription_canceled_antigo_e_descartado_com_200() {
        [$wc_sub, $vindi_id] = $this->create_fake_wc_subscription_with_vindi_meta();

        // Vindi está cancelada no banco (cenário legítimo de cancelamento).
        update_option('vindi_test_subscription_status_' . $vindi_id, 'canceled');

        $payload = (object) [
            'event' => (object) [
                'type'       => 'subscription_canceled',
                // 2 horas atrás — bem acima da janela de 30 min.
                'created_at' => gmdate('c', time() - 7200),
                'data'       => (object) [
                    'subscription' => (object) [
                        'id'   => $vindi_id,
                        'code' => 'WC-' . $wc_sub->get_id(),
                    ],
                ],
            ],
        ];

        $response = $this->dispatch_webhook($payload);

        $this->assertSame(200, $this->last_http_status,
            'Webhook antigo deveria ser aceito com 200 para a Vindi parar de tentar.');
        $this->assertStringContainsString('fora da janela',
            strtolower(json_encode($response)),
            'Mensagem do webhook antigo deveria indicar descarte por idade.');
        $this->assertSame('active', $wc_sub->get_status(),
            'Status WC não deveria ter mudado após descarte de webhook antigo.');
    }

    /**
     * @test
     * Fix 3 — Verificação de status na Vindi antes de mutar o WC.
     *
     * Se a Vindi já reativou a assinatura (status `active`), um webhook
     * `subscription_canceled` que chegar depois disso deve ser IGNORADO,
     * mesmo que esteja dentro da janela de idade. Este é o cenário exato
     * descrito na Prova 5 do ticket.
     */
    public function webhook_subscription_canceled_com_vindi_reativada_e_descartado() {
        [$wc_sub, $vindi_id] = $this->create_fake_wc_subscription_with_vindi_meta();

        // Vindi foi reativada após pagamento confirmado.
        update_option('vindi_test_subscription_status_' . $vindi_id, 'active');

        $payload = (object) [
            'event' => (object) [
                'type'       => 'subscription_canceled',
                'created_at' => gmdate('c', time() - 60), // recente
                'data'       => (object) [
                    'subscription' => (object) [
                        'id'   => $vindi_id,
                        'code' => 'WC-' . $wc_sub->get_id(),
                    ],
                ],
            ],
        ];

        $this->dispatch_webhook($payload);

        $this->assertSame('active', $wc_sub->get_status(),
            'Status WC deveria permanecer active — Vindi já reativou.');
        $this->assertSame(200, $this->last_http_status,
            'Webhook com Vindi já reativada deveria retornar 200 (sem aplicar mudança).');
    }

    /**
     * @test
     * Cenário completo: simulação end-to-end do bug original.
     */
    public function cenario_completo_bug_101648738_nao_se_manifesta() {
        [$wc_sub, $vindi_id] = $this->create_fake_wc_subscription_with_vindi_meta();

        $api_calls = [];
        $this->vindi_capture_api_calls($api_calls);

        $settings = $this->build_fake_settings();
        $handler  = new VindiPaymentGateways\VindiSubscriptionStatusHandler($settings);

        // 1. Renovação começa → on-hold
        $wc_sub->update_status('on-hold', 'Renovação iniciada');

        // 2. Pagamento fecha → active
        $wc_sub->update_status('active', 'Pagamento confirmado');

        // 3. Reentrega tardia do subscription_canceled
        update_option('vindi_test_subscription_status_' . $vindi_id, 'active');
        $payload = (object) [
            'event' => (object) [
                'type'       => 'subscription_canceled',
                'created_at' => gmdate('c', time() - 3600),
                'data'       => (object) [
                    'subscription' => (object) [
                        'id'   => $vindi_id,
                        'code' => 'WC-' . $wc_sub->get_id(),
                    ],
                ],
            ],
        ];
        $this->dispatch_webhook($payload);

        // Garantias:
        $this->assertSame('active', $wc_sub->get_status(),
            'Status final deveria ser active, não pending-cancel.');

        $delete_calls = array_filter($api_calls, fn($c) => strtoupper($c['method'] ?? '') === 'DELETE');
        $this->assertCount(0, $delete_calls,
            'Nenhum DELETE deveria ter sido disparado durante a renovação.');
    }

    // ---------- Helpers ----------

    private int $last_http_status = 0;
    private array $last_webhook_response = [];

    private function dispatch_webhook(object $payload): array {
        // Stub wp_send_json para capturar o response code e o body.
        $captured_status = null;
        $captured_body   = null;
        $GLOBALS['_vindi_last_webhook'] = [
            'status' => 200, 'body' => null,
        ];

        // Hack: wp_send_json chama exit, então em testes usamos um listener
        // alternativo via filtro.
        add_filter('pre_wp_die_handler', function ($handler) use (&$captured_status, &$captured_body) {
            return function ($message, $title = '', $args = []) use (&$captured_status, &$captured_body) {
                $captured_status = $args['response'] ?? 200;
                $captured_body   = is_string($message) ? json_decode($message, true) : $message;
                // Não chamar exit — o PHPUnit não gosta.
            };
        }, 10, 1);

        $webhooks = new VindiPaymentGateways\VindiWebhooks($this->build_fake_settings());
        try {
            $webhooks->process_event($payload);
        } catch (Throwable $e) {
            // Ignora — o teste valida os asserts no estado final.
        }

        $this->last_http_status = $captured_status ?? 200;
        $this->last_webhook_response = $captured_body ?? [];
        return $this->last_webhook_response;
    }

    private function build_fake_settings(): VindiPaymentGateways\VindiSettings {
        $settings = new class extends VindiPaymentGateways\VindiSettings {
            public function __construct() {
                $this->logger = new VindiPaymentGateways\VindiLogger();
                $this->api    = new VindiPaymentGateways\VindiApi('test', $this->logger, 'no');
                $this->routes = new VindiPaymentGateways\VindiRoutes($this);
                $this->dependencies = new class {
                    public function is_wc_memberships_active(): bool { return true; }
                };
            }
            public function get_synchronism_status(): bool { return true; }
            public function get_token(): string { return 'valid-token'; }
        };
        return $settings;
    }

    private function vindi_capture_api_calls(array &$bucket): void {
        // Hook em pre_http_request para capturar em vez de enviar.
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$bucket) {
            $bucket[] = [
                'method' => $args['method'] ?? 'POST',
                'url'    => $url,
            ];
            // Mocka resposta de acordo com a URL.
            if (str_contains($url, '/bills')) {
                return ['response' => ['code' => 200], 'body' => json_encode(['bills' => []])];
            }
            if (str_contains($url, '/subscriptions/') && str_contains($args['method'] ?? '', 'GET')) {
                $status = $GLOBALS['_vindi_test_subscription_status'] ?? 'canceled';
                return ['response' => ['code' => 200],
                        'body' => json_encode(['subscription' => ['status' => $status]])];
            }
            return ['response' => ['code' => 200], 'body' => '{}'];
        }, 10, 3);
    }
}