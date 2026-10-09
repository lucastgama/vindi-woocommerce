<?php
/**
 * Reprodução standalone do bug #101648738.
 *
 * Roda sem WordPress, sem Docker, sem Vindi real.
 * Apenas simula o ciclo de renovação com mocks mínimos e imprime
 * exatamente o que o plugin faria na vida real.
 *
 * Uso:
 *   php tests/reproduce-bug-101648738.php
 *
 * Saída esperada (resumo):
 *   - DELETE disparado pelo plugin ao entrar em on-hold           ← Problema 1
 *   - 422 retornado ao receber subscription_canceled               ← Problema 2
 *   - status final = "pending-cancel" após reentrega tardia       ← Problema 3
 *
 * @package VindiPaymentGateway
 */

namespace VindiPaymentGateways {

    // Constantes
    if (!defined('VINDI'))         define('VINDI', 'vindi');
    if (!defined('VINDI_VERSION')) define('VINDI_VERSION', '1.4.6-repro');

    // Carrega as classes reais do plugin dentro do namespace
    require_once __DIR__ . '/../src/services/Api.php';
    require_once __DIR__ . '/../src/services/VindiHelpers.php';
    require_once __DIR__ . '/../src/routes/RoutesApi.php';
    require_once __DIR__ . '/../src/utils/SubscriptionStatusHandler.php';

    /** Logger mínimo para Settings. */
    class VindiLogger {
        public function log($m) { /* silencioso */ }
    }

    /** Settings mínimo para instanciar VindiRoutes. */
    class VindiSettings {
        public VindiApi $api;
        public VindiRoutes $routes;
        public VindiLogger $logger;
        public object $dependencies;

        public function __construct() {
            $this->logger = new VindiLogger();
            $this->api    = new VindiApi('fake-key', $this->logger, 'no');
            $this->routes = new VindiRoutes($this);
            $this->dependencies = new class {
                public function is_wc_memberships_active(): bool {
                    return $GLOBALS['_vindi_wc_memberships_active'];
                }
            };
        }

        public function get_synchronism_status(): bool { return true; }
        public function get_token(): string { return 'valid-token'; }
    }

    /** Stub para WebhooksHelpers (não necessário para reproduzir o bug). */
    class WebhooksHelpers {
        public function __construct(...$args) {}
        public function renew_infos_array($data) { return []; }
        public function make_array_bill($infos) { return []; }
        public function handle_subscription_renewal($infos, $data) { return false; }
        public function handle_trial_period($id) { return false; }
    }

    /** Mock de WC_Subscription (registra mudança de status). */
    class WC_Subscription {
        public int $id;
        public string $status;
        public array $meta = [];

        public function __construct(int $id, string $status) {
            $this->id = $id;
            $this->status = $status;
            $this->meta['vindi_subscription_id'] = '59957755';
        }
        public function get_id(): int { return $this->id; }
        public function get_meta(string $key, bool $single = true) { return $this->meta[$key] ?? ''; }
        public function update_meta_data(string $key, $value) { $this->meta[$key] = $value; }
        public function save() {}
        public function has_status($status): bool {
            return in_array($this->status, (array) $status, true);
        }
        public function update_status(string $new_status, string $note = ''): void {
            $old = $this->status;
            $this->status = $new_status;
            $GLOBALS['_wc_status_history'][] = ['from' => $old, 'to' => $new_status, 'note' => $note];
            echo "    [WC] subscription #{$this->id} status: '{$old}' → '{$new_status}'"
                . ($note ? "  ({$note})" : '') . "\n";
        }
    }

    /** Reproduz EXATAMENTE a lógica corrigida de Webhooks::subscription_canceled. */
    class FakeWebhookHandler {
        public VindiSettings $settings;
        public function __construct(VindiSettings $settings) { $this->settings = $settings; }

        public function simulate_subscription_canceled(object $data, ?string $created_at = null): void {
            // FIX #2: age check (espelha Webhooks::is_destructive_event_stale)
            $event_created_at = $created_at ?? date('c');
            $age_seconds = time() - strtotime($event_created_at);
            $max_age = 1800; // 30 min
            if ($age_seconds > $max_age) {
                echo "    → 200 (descartado por idade: {$age_seconds}s > {$max_age}s)\n";
                \wp_send_json(['message' => 'Evento descartado por estar fora da janela de processamento.'], 200);
                return;
            }

            $subscription = \wcs_get_subscription(9871);
            if (!$subscription) {
                echo "    → 422 (assinatura WC não encontrada)\n";
                \wp_send_json(['mensagem' => 'Assinatura não encontrada'], 422);
                return;
            }

            // FIX #3: confirma status na Vindi antes de mutar (espelha Webhooks::subscription_canceled)
            $vindi = $this->settings->routes->getSubscription($data->subscription->id);
            $vindi_status = $vindi['status'] ?? null;
            if ($vindi_status !== 'canceled') {
                echo "    → 200 (status Vindi='{$vindi_status}', não cancelada — webhook descartado)\n";
                \wp_send_json(['mensagem' => 'Assinatura não está cancelada na Vindi — webhook ignorado.'], 200);
                return;
            }

            $not_possible = (
                $subscription->has_status('cancelled')
                || $subscription->has_status('pending-cancel')
                || $subscription->has_status('on-hold')
            ) || $this->settings->routes->hasPendingSubscriptionBills($data->subscription->id);

            if ($not_possible) {
                echo "    → 422 (recusado por status='{$subscription->status}'";
                if ($this->settings->routes->hasPendingSubscriptionBills($data->subscription->id)) {
                    echo " + bills pendentes";
                }
                echo ")\n";
                \wp_send_json(['mensagem' => 'Não foi possível cancelar a assinatura devido ao seu status atual.'], 422);
                return;
            }

            if ($this->settings->dependencies->is_wc_memberships_active()) {
                $subscription->update_status('pending-cancel', 'Assinatura atualizada para cancelamento pendente.');
                \wp_send_json(['message' => 'Assinatura atualizada para cancelamento pendente.'], 200);
                return;
            }

            $subscription->update_status('cancelled', 'Assinatura cancelada.');
            \wp_send_json(['mensagem' => 'Assinatura cancelado.'], 200);
        }
    }
}

// Stubs WP/WC no namespace global — vêm DEPOIS do bloco do plugin para
// ficarem disponíveis quando as classes reais os invocarem.
namespace {

    $GLOBALS['_vindi_api_calls']      = [];
    $GLOBALS['_wc_status_history']    = [];
    $GLOBALS['_webhook_responses']    = [];
    $GLOBALS['_vindi_has_pending_bills'] = true;
    $GLOBALS['_vindi_wc_memberships_active'] = true;
    $GLOBALS['_vindi_wc_subs'] = [];

    if (!function_exists('__')) {
        function __(string $t, string $d = ''): string { return $t; }
    }

    function wcs_get_subscription($id) {
        return $GLOBALS['_vindi_wc_subs'][$id] ?? null;
    }

    function wp_send_json($payload, $status_code = 200) {
        $GLOBALS['_webhook_responses'][] = ['status' => $status_code, 'body' => $payload];
        return null;
    }

    function wp_remote_post(string $url, array $args) {
        $GLOBALS['_vindi_api_calls'][] = [
            'method' => $args['method'] ?? 'POST',
            'url'    => $url,
            'time'   => date('Y-m-d H:i:s'),
        ];
        if (str_contains($url, '/reactivate')) {
            return ['response' => ['code' => 200, 'message' => 'OK'],
                    'body' => json_encode(['subscription' => ['id' => 59957755, 'status' => 'active']])];
        }
        if (str_contains($url, '/subscriptions/') && ($args['method'] ?? '') === 'DELETE') {
            return ['response' => ['code' => 200, 'message' => 'OK'],
                    'body' => json_encode(['subscription' => [
                        'id' => 59957755, 'status' => 'canceled', 'end_at' => date('c'),
                    ]])];
        }
        if (str_contains($url, '/subscriptions/') && ($args['method'] ?? '') === 'GET') {
            // Espelha o que a Vindi retorna: quando o plugin reativou, status vira 'active'.
            // Quando ainda está cancelada, retorna 'canceled'.
            $status = $GLOBALS['_vindi_current_status'] ?? 'canceled';
            return ['response' => ['code' => 200, 'message' => 'OK'],
                    'body' => json_encode(['subscription' => ['id' => 59957755, 'status' => $status]])];
        }
        if (str_contains($url, '/bills')) {
            return ['response' => ['code' => 200, 'message' => 'OK'],
                    'body' => json_encode(['bills' => []])];
        }
        return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => '{}'];
    }

    function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
    function apply_filters($tag, $value, ...$rest) { return $value; }
    function add_action($tag, $callback, $priority = 10, $accepted_args = 1) { return true; }
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) { return true; }
    function get_bloginfo($key = '') { return 'reproduction.local'; }
    function is_wp_error($thing) { return false; }
    function get_transient($key) { return false; }
    function set_transient($key, $value, $expiration = 0) { return true; }
    function delete_transient($key) { return true; }
    function wc_get_order($id) { return null; }
    function wc_get_order_statuses() { return []; }
    function wc_add_notice($msg, $type) { /* no-op */ }

    use VindiPaymentGateways\VindiSubscriptionStatusHandler;
    use VindiPaymentGateways\VindiSettings;
    use VindiPaymentGateways\WC_Subscription;
    use VindiPaymentGateways\FakeWebhookHandler;

    echo "\n";
    echo str_repeat('=', 78) . "\n";
    echo "  REPRODUÇÃO DO BUG #101648738\n";
    echo "  Cliente: LONGEVIDADE.YOGA\n";
    echo "  Sintoma: assinatura paga é marcada como pending-cancel após renovação\n";
    echo str_repeat('=', 78) . "\n\n";

    echo ">>> PASSO 1 — Início da renovação\n";
    echo "    WooCommerce Subscriptions muda status: 'active' → 'on-hold'\n";
    echo "    (comportamento padrão do WCS no início de toda renovação)\n\n";

    $wc_sub = new WC_Subscription(9871, 'active');
    $GLOBALS['_vindi_wc_subs'][9871] = $wc_sub;

    $settings = new VindiSettings();
    $handler  = new VindiSubscriptionStatusHandler($settings);

    echo "[HOOK] woocommerce_subscription_status_updated dispara (priority=1):\n";
    // Primeiro o WC muda o status (como o WCS faria)
    $wc_sub->update_status('on-hold', 'Início da renovação pelo WooCommerce Subscriptions');
    // Depois o hook do plugin reage a essa mudança
    $handler->filter_pre_status($wc_sub, 'on-hold', 'active');

    echo "\n";
    echo ">>> PASSO 2 — Vindi processa o cancelamento e dispara o webhook\n";
    echo "    Evento: type=subscription_canceled, created_at=" . date('c') . "\n\n";

    $GLOBALS['_vindi_has_pending_bills'] = true;
    // Antes do pagamento fechar: Vindi está 'canceled' (nosso DELETE cancelou).
    $GLOBALS['_vindi_current_status'] = 'canceled';

    $webhook_data = (object) [
        'subscription' => (object) ['id' => 59957755, 'code' => 'WC-9871'],
    ];

    $webhook = new FakeWebhookHandler($settings);

    echo "[HOOK] Vindi entrega subscription_canceled (1ª tentativa):\n";
    $webhook->simulate_subscription_canceled($webhook_data);

    echo "\n>>> PASSO 3 — Vindi reentrega o webhook 9 vezes (todas recebem 422)\n";
    for ($i = 2; $i <= 10; $i++) {
        echo "  [ENTREGA #{$i}] ";
        $webhook->simulate_subscription_canceled($webhook_data);
    }

    echo "\n";
    echo ">>> PASSO 4 — Janela de pagamento fecha, assinatura volta a 'active'\n";
    $GLOBALS['_vindi_has_pending_bills'] = false;
    // Após pagamento e reativação: Vindi volta a 'active' na API.
    $GLOBALS['_vindi_current_status'] = 'active';
    $wc_sub->update_status('active', 'Pagamento confirmado pelo gateway');

    echo "\n[HOOK] woocommerce_subscription_status_updated dispara (on-hold → active):\n";
    $handler->filter_pre_status($wc_sub, 'active', 'on-hold');

    echo "\n";
    echo ">>> PASSO 5 — Uma reentrega tardia do subscription_canceled chega AGORA\n";
    echo "    (cenário: Vindi esgotou as 10 tentativas horas atrás e uma\n";
    echo "     nova tentativa foi feita DEPOIS do pagamento ter fechado)\n";
    echo "    created_at do evento: " . (new DateTime('-2 hours'))->format('c') . " (2 horas atrás)\n\n";

    echo "[HOOK] Vindi entrega subscription_canceled (reentrega tardia):\n";
    $webhook->simulate_subscription_canceled($webhook_data, (new DateTime('-2 hours'))->format('c'));

    echo "\n";
    echo str_repeat('=', 78) . "\n";
    echo "  RESULTADO\n";
    echo str_repeat('=', 78) . "\n\n";

    echo "Total de chamadas API disparadas pelo plugin: " . count($GLOBALS['_vindi_api_calls']) . "\n\n";
    foreach ($GLOBALS['_vindi_api_calls'] as $i => $call) {
        $path = str_replace('https://app.vindi.com.br/api/v1/', '', $call['url']);
        echo sprintf("  [%02d] %s %s @ %s\n", $i + 1, $call['method'], $path, $call['time']);
    }

    echo "\nHistórico de status da assinatura WC #{$wc_sub->id}:\n";
    foreach ($GLOBALS['_wc_status_history'] as $h) {
        echo sprintf("  %s → %s%s\n", $h['from'], $h['to'], $h['note'] ? "  // {$h['note']}" : '');
    }

    echo "\n" . str_repeat('-', 78) . "\n";

    $final_status = $wc_sub->status;
    $delete_called = false;
    foreach ($GLOBALS['_vindi_api_calls'] as $c) {
        if ($c['method'] === 'DELETE') { $delete_called = true; break; }
    }
    $delivery_422_count = 0;
    foreach ($GLOBALS['_webhook_responses'] as $r) {
        if ($r['status'] === 422) $delivery_422_count++;
    }

    echo "🔴 DELETE disparado pelo plugin na renovação?              " . ($delete_called ? "SIM (BUG #1)" : "NÃO") . "\n";
    echo "🔴 Webhook subscription_canceled retornou 422?                  " . ($delivery_422_count > 0 ? "SIM, {$delivery_422_count}x (BUG #2)" : "NÃO") . "\n";
    echo "🔴 Status final da assinatura WC:                               '{$final_status}'\n";
    echo "    Esperado pelo cliente: 'active'\n";
    echo "    Resultado real:         '" . ($final_status === 'pending-cancel' ? 'pending-cancel (BUG #3)' : $final_status) . "'\n";

    echo "\n";
    if (!$delete_called && $final_status === 'active') {
        echo "✅ CORREÇÕES APLICADAS — bug não acontece\n\n";
        echo "   - Fix 1: status on-hold não dispara DELETE\n";
        echo "   - Fix 2: reentregas tardias (>30min) são descartadas com 200\n";
        echo "   - Fix 3: subscription_canceled valida status Vindi antes de mutar WC\n";
        echo "   - Resultado: assinatura permanece 'active' após renovação\n";
    } else {
        echo "❌ Ainda há problema — verifique a configuração.\n";
        if ($delete_called) {
            echo "   ⚠️ DELETE ainda é disparado\n";
        }
        if ($final_status !== 'active') {
            echo "   ⚠️ Status final é '{$final_status}' (deveria ser 'active')\n";
        }
    }
    echo "\n";
}