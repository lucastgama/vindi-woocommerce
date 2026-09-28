<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<?php
$consent_url    = isset($consent_url) ? (string) $consent_url : '';
$consent_status = isset($consent_status) ? (string) $consent_status : '';
$order_id       = isset($order) ? $order->get_id() : 0;
?>
<div class="vindi-pix-automatico-consent" style="padding: 24px; border: 2px solid #0073aa; border-radius: 8px; margin: 24px 0; background: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
    <h3 style="margin-top: 0; color: #0073aa;">
        <?php _e('🔐 Autorize o Pix Automático no seu banco', VINDI); ?>
    </h3>

    <?php if ('authorized' === $consent_status): ?>
        <div style="padding: 12px; background: #d4edda; border-left: 4px solid #28a745; border-radius: 4px; margin: 16px 0;">
            <strong style="color: #155724;"><?php _e('✓ Pix Automático autorizado com sucesso!', VINDI); ?></strong>
            <p style="margin: 8px 0 0 0; color: #155724;">
                <?php _e('Sua assinatura está ativa. A próxima cobrança será processada automaticamente via Pix.', VINDI); ?>
            </p>
        </div>
    <?php elseif ('rejected' === $consent_status || 'expired' === $consent_status || 'revoked' === $consent_status): ?>
        <div style="padding: 12px; background: #f8d7da; border-left: 4px solid #dc3545; border-radius: 4px; margin: 16px 0;">
            <strong style="color: #721c24;">
                <?php _e('⚠ Consentimento não autorizado', VINDI); ?>
            </strong>
            <p style="margin: 8px 0 0 0; color: #721c24;">
                <?php _e('A autorização do Pix Automático foi recusada ou expirou. Por favor, tente novamente.', VINDI); ?>
            </p>
        </div>
        <?php if (!empty($consent_url)): ?>
            <a href="<?php echo esc_url($consent_url); ?>" target="_blank" rel="noopener" class="button button-primary" style="background: #0073aa; color: #fff; padding: 14px 28px; font-size: 16px; font-weight: 600; border-radius: 6px; text-decoration: none; display: inline-block; margin-top: 12px;">
                <?php _e('Tentar autorizar novamente', VINDI); ?>
            </a>
        <?php endif; ?>
    <?php else: ?>
        <p style="font-size: 15px; line-height: 1.6; color: #333;">
            <?php _e('Para ativar sua assinatura, clique no botão abaixo e autorize o débito automático no aplicativo do seu banco. O link expira em <strong>60 minutos</strong>.', VINDI); ?>
        </p>

        <ol style="color: #555; line-height: 1.8; padding-left: 20px;">
            <li><?php _e('Clique no botão abaixo', VINDI); ?></li>
            <li><?php _e('Você será redirecionado para o app do seu banco', VINDI); ?></li>
            <li><?php _e('Confirme a autorização do débito automático', VINDI); ?></li>
            <li><?php _e('Pronto! Sua assinatura estará ativa', VINDI); ?></li>
        </ol>

        <?php if (!empty($consent_url)): ?>
            <a href="<?php echo esc_url($consent_url); ?>" target="_blank" rel="noopener" class="button button-primary" style="background: #0073aa; color: #fff; padding: 14px 28px; font-size: 16px; font-weight: 600; border-radius: 6px; text-decoration: none; display: inline-block; margin-top: 12px;">
                <?php _e('🔗 Autorizar Pix Automático no banco', VINDI); ?>
            </a>

            <details style="margin-top: 20px; font-size: 13px; color: #666;">
                <summary style="cursor: pointer; color: #0073aa;"><?php _e('O botão não funciona? Copie o link', VINDI); ?></summary>
                <div style="padding: 12px; background: #fff; border: 1px dashed #ccc; border-radius: 4px; margin-top: 8px; word-break: break-all;">
                    <code style="font-size: 12px; color: #333;"><?php echo esc_html($consent_url); ?></code>
                </div>
            </details>
        <?php else: ?>
            <div style="padding: 12px; background: #f8d7da; border-left: 4px solid #dc3545; border-radius: 4px; margin: 16px 0;">
                <strong style="color: #721c24;">
                    <?php _e('Link de consentimento indisponível', VINDI); ?>
                </strong>
                <p style="margin: 8px 0 0 0; color: #721c24;">
                    <?php _e('Entre em contato com o suporte informando o número do pedido.', VINDI); ?>
                </p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>