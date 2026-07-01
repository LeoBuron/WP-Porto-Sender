<?php
declare(strict_types=1);
namespace PortoSender\Frontend;

use PortoSender\Postage\ProductCatalog;
use PortoSender\Settings\Settings;

final class RequestForm
{
    public function __construct(private ProductCatalog $catalog, private Settings $settings) {}

    public function enqueueAssets(): void
    {
        $base = plugins_url('assets/', dirname(__DIR__, 2) . '/porto-sender.php');
        wp_enqueue_style('porto-form', $base . 'porto-form.css', [], '1.0.0');
        wp_enqueue_script('porto-altcha', $base . 'altcha.min.js', [], '1.0.0', true);
        wp_enqueue_script('porto-form', $base . 'porto-form.js', ['porto-altcha'], '1.0.0', true);
    }

    public function render(array $atts): string
    {
        $products = $this->catalog->enabled($this->settings->enabledProducts());
        $challengeUrl = rest_url('porto/v1/altcha');
        $restUrl = rest_url('porto/v1/request');
        $privacy = $this->settings->privacyPolicyUrl();

        $layout = $this->settings->formLayout();
        $intro = $this->settings->text('text_intro');

        // Scoped custom properties driving assets/porto-form.css. Colours are already
        // hex-sanitised by Settings::sanitize() (defence against CSS injection) and the
        // sizes are integers, so the value string is safe to emit inside <style>.
        // form_max_width_px = 0 means "no limit" → max-width: none (full width).
        $maxWidth = $this->settings->formMaxWidthPx();
        $styleVars = sprintf(
            '--porto-accent:%s;--porto-btn-bg:%s;--porto-btn-text:%s;--porto-max-width:%s;--porto-gap:%dpx',
            $this->settings->formAccentColor(),
            $this->settings->formButtonBg(),
            $this->settings->formButtonText(),
            $maxWidth > 0 ? $maxWidth . 'px' : 'none',
            $this->settings->formFieldGapPx()
        );

        // Destination porto-form.js navigates to after a confirmation e-mail is sent.
        // A GET navigation means reloading the "check your e-mail" page never re-POSTs.
        $sentUrl = $this->sentUrl();

        ob_start(); ?>
<style>.porto-request-form{<?php echo $styleVars; ?>}</style>
<form class="porto-request-form porto-layout-<?php echo esc_attr($layout); ?>" data-endpoint="<?php echo esc_attr($restUrl); ?>" data-sent-url="<?php echo esc_url($sentUrl); ?>" autocomplete="off">
  <?php if ($intro !== ''): ?><p class="porto-intro"><?php echo esc_html($intro); ?></p><?php endif; ?>
  <p><label><?php echo esc_html($this->settings->text('text_label_name')); ?><br>
    <input type="text" name="porto_name" required autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"></label></p>
  <p><label><?php echo esc_html($this->settings->text('text_label_email')); ?><br>
    <input type="email" name="porto_email" required autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"></label></p>
  <fieldset>
    <legend><?php echo esc_html($this->settings->text('text_legend_products')); ?></legend>
    <?php foreach ($products as $p): ?>
      <label><input type="radio" name="porto_product" value="<?php echo esc_attr($p->key); ?>" required>
        <?php echo esc_html($p->label . ' – ' . $p->limits); ?></label><br>
    <?php endforeach; ?>
  </fieldset>
  <altcha-widget challenge="<?php echo esc_attr($challengeUrl); ?>" auto="onfocus"></altcha-widget>
  <p><label><input type="checkbox" name="porto_consent" required>
    <?php echo esc_html($this->settings->text('text_consent')); ?>
    <?php if ($privacy !== ''): ?><a href="<?php echo esc_url($privacy); ?>" target="_blank"><?php echo esc_html__('Datenschutz', 'wp-porto-sender'); ?></a><?php endif; ?>
  </label></p>
  <button type="submit"><?php echo esc_html($this->settings->text('text_button')); ?></button>
  <div class="porto-message" role="status"></div>
</form>
<?php
        return (string) ob_get_clean();
    }

    /**
     * The "check your e-mail" destination carried on the form as data-sent-url:
     * a chosen (published) page permalink, else the plugin's built-in themed view.
     * Both carry ?porto_view=sent so PageRenderer / the override page shows the notice.
     */
    private function sentUrl(): string
    {
        $pageId = PageRenderer::resolvePageId($this->settings->pageSent());
        $base = $pageId > 0 ? get_permalink($pageId) : home_url('/');
        return add_query_arg('porto_view', 'sent', $base);
    }
}
