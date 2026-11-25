<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="vindi-pix-automatico-status" style="padding: 15px; border: 1px solid #ddd; border-radius: 4px; margin: 15px 0;">
    <h4 style="margin-top: 0;"><?php _e('Status do consentimento', VINDI); ?></h4>
    <?php if ('authorized' === $consent_status): ?>
        <p style="color: #46b450;">
            <?php _e('Autorizado. Sua assinatura está ativa.', VINDI); ?>
        </p>
    <?php elseif ('rejected' === $consent_status): ?>
        <p style="color: #d63638;">
            <?php _e('Autorização recusada no banco. Entre em contato com o suporte para escolher outra forma de pagamento.', VINDI); ?>
        </p>
    <?php elseif ('revoked' === $consent_status): ?>
        <p style="color: #d63638;">
            <?php _e('Consentimento revogado pelo cliente no banco.', VINDI); ?>
        </p>
    <?php elseif ('in_progress' === $consent_status): ?>
        <p style="color: #0073aa;">
            <?php _e('Cliente acessou o link no banco. Aguardando autorização.', VINDI); ?>
        </p>
    <?php elseif (!empty($consent_expired)): ?>
        <p style="color: #d63638;">
            <?php _e('O link de autorização expirou. Entre em contato com o suporte para receber um novo link.', VINDI); ?>
        </p>
    <?php else: ?>
        <p style="color: #0073aa;">
            <?php _e('Aguardando autorização.', VINDI); ?>
            <?php if (!empty($consent_url)): ?>
                <a href="<?php echo esc_url($consent_url); ?>" target="_blank">
                    <?php _e('Autorizar agora', VINDI); ?>
                </a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</div>