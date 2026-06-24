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
        $base = plugins_url('assets/', dirname(__DIR__) . '/porto-sender.php');
        wp_enqueue_script('porto-altcha', $base . 'altcha.min.js', [], '1.0.0', true);
        wp_enqueue_script('porto-form', $base . 'porto-form.js', ['porto-altcha'], '1.0.0', true);
    }

    public function render(array $atts): string
    {
        $products = $this->catalog->enabled($this->settings->enabledProducts());
        $challengeUrl = rest_url('porto/v1/altcha');
        $restUrl = rest_url('porto/v1/request');
        $privacy = $this->settings->privacyPolicyUrl();

        ob_start(); ?>
<form class="porto-request-form" data-endpoint="<?php echo esc_attr($restUrl); ?>">
  <p><label><?php echo esc_html__('Name', 'wp-porto-sender'); ?><br>
    <input type="text" name="porto_name" required></label></p>
  <p><label><?php echo esc_html__('E-Mail', 'wp-porto-sender'); ?><br>
    <input type="email" name="porto_email" required></label></p>
  <fieldset>
    <legend><?php echo esc_html__('Was möchtest du senden?', 'wp-porto-sender'); ?></legend>
    <?php foreach ($products as $p): ?>
      <label><input type="radio" name="porto_product" value="<?php echo esc_attr($p->key); ?>" required>
        <?php echo esc_html($p->label . ' – ' . $p->limits); ?></label><br>
    <?php endforeach; ?>
  </fieldset>
  <altcha-widget challenge="<?php echo esc_attr($challengeUrl); ?>"></altcha-widget>
  <p><label><input type="checkbox" name="porto_consent" required>
    <?php echo esc_html__('Ich bin einverstanden, dass mein Name und meine E-Mail zur Zusendung des Codes verarbeitet werden.', 'wp-porto-sender'); ?>
    <?php if ($privacy !== ''): ?><a href="<?php echo esc_url($privacy); ?>" target="_blank"><?php echo esc_html__('Datenschutz', 'wp-porto-sender'); ?></a><?php endif; ?>
  </label></p>
  <button type="submit"><?php echo esc_html__('Porto-Code anfordern', 'wp-porto-sender'); ?></button>
  <div class="porto-message" role="status"></div>
</form>
<?php
        return (string) ob_get_clean();
    }
}
