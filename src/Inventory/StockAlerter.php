<?php
declare(strict_types=1);
namespace PortoSender\Inventory;

use PortoSender\Settings\Settings;
use PortoSender\Mail\MailerInterface;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Support\Clock;

final class StockAlerter
{
    public function __construct(
        private CodeStore $codes,
        private Settings $settings,
        private MailerInterface $mailer,
        private ProductCatalog $catalog,
        private Clock $clock,
    ) {}

    public function evaluate(): void
    {
        $to = $this->settings->alertEmail();
        if ($to === '') { return; }
        $now = $this->clock->now();

        foreach ($this->settings->enabledProducts() as $key) {
            $product = $this->catalog->get($key);
            if ($product === null) { continue; }
            $available = $this->codes->availableCount($key, $now);
            $threshold = $this->settings->lowStockThreshold($key);
            $flagKey = 'porto_sender_lowstock_' . $key;
            $flag = (string) get_option($flagKey, '');

            if ($available <= 0) {
                if ($flag !== 'out') {
                    $this->mailer->sendOutOfStock($to, $product->label);
                    update_option($flagKey, 'out');
                }
            } elseif ($available <= $threshold) {
                if ($flag === 'out') {
                    // Partial restock after an out-of-stock alert: re-arm to 'low' silently
                    // (no duplicate alert) so a later drop back to 0 re-fires out-of-stock.
                    update_option($flagKey, 'low');
                } elseif ($flag !== 'low') {
                    $this->mailer->sendLowStock($to, $product->label, $available);
                    update_option($flagKey, 'low');
                }
            } elseif ($flag !== '') {
                delete_option($flagKey);
            }
        }
    }
}
