<?php
/**
 * Verificação: cancelamento legítimo (não renovação) continua funcionando.
 *
 * Garante que as correções do bug #101648738 NÃO quebraram o caminho
 * de cancelamento legítimo (cliente cancelou pela conta, admin cancelou
 * pelo painel, etc.).
 *
 * Uso:
 *   php tests/verify-fix-101648738.php
 *
 * @package VindiPaymentGateway
 */

namespace VindiPaymentGateways {
    // Stub: re-executa o setup mínimo sem repetir tudo do reproduce-bug.
    if (!defined('VINDI')) define('VINDI', 'vindi');
}

namespace {

    // Para reaproveitar tudo, rodamos primeiro o script de reprodução em modo "no echo"
    // e depois usamos o ambiente já carregado.
    ob_start();
    require_once __DIR__ . '/reproduce-bug-101648738.php';
    ob_end_clean();

    use VindiPaymentGateways\VindiSubscriptionStatusHandler;
    use VindiPaymentGateways\VindiSettings;
    use VindiPaymentGateways\WC_Subscription;
    use VindiPaymentGateways\FakeWebhookHandler;

    echo "\n";
    echo str_repeat('=', 78) . "\n";
    echo "  VERIFICAÇÃO: cancelamento legítimo continua funcionando\n";
    echo str_repeat('=', 78) . "\n\n";

    // Reset estado
    $GLOBALS['_vindi_api_calls']      = [];
    $GLOBALS['_wc_status_history']    = [];
    $GLOBALS['_webhook_responses']    = [];
    $GLOBALS['_vindi_has_pending_bills'] = false;
    $GLOBALS['_vindi_current_status'] = 'active';

    $wc_sub = new WC_Subscription(9872, 'active');
    $GLOBALS['_vindi_wc_subs'][9872] = $wc_sub;

    $settings = new VindiSettings();
    $handler  = new VindiSubscriptionStatusHandler($settings);

    // Cenário: cliente cancelou pela conta (WC vai direto a 'cancelled').
    // O plugin DEVE chamar DELETE na Vindi (comportamento correto).
    echo ">>> Cenário legítimo: cliente cancela pela conta\n";
    echo "    WC: active → cancelled\n\n";

    $wc_sub->update_status('cancelled', 'Subscription cancelled by the subscriber from their account page.');
    $handler->filter_pre_status($wc_sub, 'cancelled', 'active');

    $delete_called = false;
    foreach ($GLOBALS['_vindi_api_calls'] as $c) {
        if ($c['method'] === 'DELETE') { $delete_called = true; break; }
    }

    echo "🔍 DELETE enviado para Vindi após cancelamento legítimo?    "
        . ($delete_called ? "✅ SIM (correto)" : "❌ NÃO (regressão!)") . "\n";

    // Agora a Vindi devolve o webhook subscription_canceled (criado agora, dentro da janela)
    // A Vindi está canceled, então o plugin DEVE aplicar 'cancelled' no WC.
    echo "\n>>> Vindi processa e dispara subscription_canceled (legítimo)\n";

    $GLOBALS['_vindi_current_status'] = 'canceled';
    $webhook_data = (object) [
        'subscription' => (object) ['id' => 59957755, 'code' => 'WC-9872'],
    ];
    $webhook = new FakeWebhookHandler($settings);

    echo "[HOOK] Vindi entrega subscription_canceled:\n";
    $webhook->simulate_subscription_canceled($webhook_data);

    $final = $wc_sub->status;
    echo "\n🔍 Status final após cancelamento legítimo: '{$final}'  →  "
        . (in_array($final, ['cancelled', 'pending-cancel'], true) ? "✅ correto" : "❌ não aplicou")
        . "\n";

    if ($delete_called && in_array($final, ['cancelled', 'pending-cancel'], true)) {
        echo "\n✅ Cancelamento legítimo continua funcionando normalmente.\n";
    } else {
        echo "\n❌ REGRESSÃO detectada — o caminho legítimo foi afetado.\n";
    }
    echo "\n";
}