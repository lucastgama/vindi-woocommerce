<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="vindi-pix-automatico-consent" style="padding: 20px; border: 2px solid #0073aa; border-radius: 4px; margin: 20px 0;">
    <h3 style="margin-top: 0;"><?php _e('Autorize o Pix Automático no seu banco', VINDI); ?></h3>
    <p>
        <?php _e(
            'Para ativar sua assinatura, clique no botão abaixo e autorize o débito automático no aplicativo do seu banco. O link expira em 60 minutos e também fica disponível em Minha Conta > Pedidos.',
            VINDI
        ); ?>
    </p>
    <?php if (!empty($consent_url)): ?>
        <a href="<?php echo esc_url($consent_url); ?>" target="_blank" class="button button-primary" style="margin-top: 10px;">
            <?php _e('Autorizar Pix Automático no banco', VINDI); ?>
        </a>
    <?php else: ?>
        <p style="color: #d63638;">
            <?php _e('Link de consentimento indisponível. Entre em contato com o suporte.', VINDI); ?>
        </p>
    <?php endif; ?>
</div>