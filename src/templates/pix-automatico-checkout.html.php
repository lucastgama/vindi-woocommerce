<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<fieldset>
    <?php do_action('vindi_pix_automatico_form_start'); ?>

    <div class="vindi-pix-automatico-description" style="padding: 20px 0; font-weight: bold;">
        <p>
            <strong><?php _e('Pix Automático', VINDI); ?></strong>
        </p>
        <p style="font-weight: normal;">
            <?php _e(
                'Após confirmar o pedido, você será redirecionado ao seu banco para autorizar os débitos automáticos desta assinatura. A autorização é única e todas as cobranças futuras serão debitadas automaticamente da sua conta.',
                VINDI
            ); ?>
        </p>
        <p style="font-weight: normal; font-size: 0.9em; color: #666;">
            <?php _e('Disponível apenas para assinaturas/recorrências.', VINDI); ?>
        </p>
    </div>
    <div class="clear"></div>

    <?php do_action('vindi_pix_automatico_form_end'); ?>

    <div class="clear"></div>
</fieldset>