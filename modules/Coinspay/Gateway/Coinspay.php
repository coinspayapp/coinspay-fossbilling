<?php

/**
 * CoinsPay for FOSSBilling — the payment adapter.
 *
 * FOSSBilling loads it from library/Payment/Adapter/Coinspay. The CoinsPay module keeps this copy
 * in modules/Coinspay/Gateway and writes it there when activated, so do not edit it in library:
 * the next activation or update of the module replaces it. FOSSBilling lists every PHP file of
 * that folder as a gateway, so this is the only one.
 *
 * The gateway itself lives in the module, which this adapter calls. The module takes the webhook
 * of CoinsPay, polls with the cron and adds payments to the balance of the client exactly once.
 *
 * @license GPL-3.0-or-later
 */

use Box\Mod\Coinspay\Lib\Bridge;
use Box\Mod\Coinspay\Lib\FossbillingHost;
use Box\Mod\Coinspay\Lib\Settings;
use Box\Mod\Coinspay\Lib\Text;
use Box\Mod\Coinspay\Lib\Util;

class Payment_Adapter_Coinspay implements FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;

    /**
     * FOSSBilling builds the adapter for every list of gateways, and when the admin saves it with
     * only its own settings: nothing here may fail.
     */
    public function __construct(protected array $config = []) {}

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public static function getConfig(): array
    {
        $text = new Text(self::pageLocale());
        $yesNo = fn (bool $default) => $default
            ? ['1' => $text->t('field_yes'), '0' => $text->t('field_no')]
            : ['0' => $text->t('field_no'), '1' => $text->t('field_yes')];
        $flag = fn (string $key) => ['select', [
            'label' => $text->t('field_' . $key),
            'description' => $text->t('field_' . $key . '_help'),
            // A select without a saved value shows its first option: the default comes first.
            'multiOptions' => $yesNo(!empty(Settings::FLAGS[$key])),
            'required' => false,
        ]];

        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            // FOSSBilling shows it as HTML: the text escaped, the page of the module a link.
            'description' => str_replace(
                '{link}',
                '<a href="' . Util::html(self::modulePage()) . '">' . Util::html(self::modulePage()) . '</a>',
                Util::html($text->t('gateway_description', '{link}'))
            ),
            'logo' => ['logo' => 'Coinspay/logo.svg', 'height' => '30px', 'width' => '30px'],
            'form' => [
                'api_key' => ['password', [
                    'label' => $text->t('field_api_key'),
                    'description' => $text->t('field_api_key_help'),
                    'required' => false,
                    'secret' => true,
                ]],
                'webhook_secret' => ['password', [
                    'label' => $text->t('field_webhook_secret'),
                    'description' => $text->t('field_webhook_secret_help'),
                    'required' => false,
                    'secret' => true,
                ]],
                'api_url' => ['text', [
                    'label' => $text->t('field_api_url'),
                    'description' => $text->t('field_api_url_help'),
                    'required' => false,
                ]],
                'lifetime' => ['number', [
                    'label' => $text->t('field_lifetime'),
                    'description' => $text->t('field_lifetime_help'),
                    'required' => false,
                ]],
                'auto_return' => $flag('auto_return'),
                'stablecoins_only' => $flag('stablecoins_only'),
                'buyer_pays_fee' => $flag('buyer_pays_fee'),
                'buyer_pays_network' => $flag('buyer_pays_network'),
                'test_admin_only' => $flag('test_admin_only'),
                'apply_test' => $flag('apply_test'),
                'debug' => $flag('debug'),
            ],
        ];
    }

    /** Masked in the admin area and its API from FOSSBilling 0.8.7. */
    public static function getSecretFields(): array
    {
        return ['api_key', 'webhook_secret'];
    }

    /**
     * The client picked CoinsPay on an invoice: to the payment page of a CoinsPay invoice for what
     * is left to pay, or a message.
     */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $di = $this->container();
        $module = Bridge::module($di, Bridge::PAGE_TIMEOUT);
        $text = new Text($module->host()->userLanguage());
        try {
            $result = $module->payment()->start((int) $invoice_id);
        } catch (Throwable $e) {
            $module->host()->log('error', 'invoice #' . (int) $invoice_id . ': ' . $e->getMessage());
            $result = ['error' => $text->t('error_api')];
        }

        if (isset($result['redirect'])) {
            $url = Util::html($result['redirect']);
            $json = json_encode((string) $result['redirect'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

            return '<div class="coinspay-pay"><p>' . Util::html($text->t('redirect_text')) . '</p>'
                . '<p><a class="btn btn-primary" href="' . $url . '">' . Util::html($text->t('redirect_link')) . '</a></p></div>'
                . '<script>window.location.replace(' . $json . ');</script>';
        }

        $back = $module->host()->invoiceUrl((int) $invoice_id);

        return '<div class="coinspay-pay"><div class="alert alert-warning">' . Util::html((string) ($result['error'] ?? $text->t('error_unavailable'))) . '</div>'
            . '<p><a class="btn btn-secondary" href="' . Util::html($back) . '">' . Util::html($text->t('back_link')) . '</a></p></div>';
    }

    /**
     * A callback through ipn.php, or an admin pressing "Process" on a transaction of the gateway.
     * CoinsPay sends its webhook to the module, so nothing here is trusted: the CoinsPay invoices
     * of the invoice are read from the API, and their payments go the module's way, exactly once.
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        Bridge::transaction($this->container(), (int) $id);

        return true;
    }

    private function container(): Pimple\Container
    {
        if ($this->di === null) {
            global $di;
            $this->di = $di;
        }

        return $this->di;
    }

    /** The language the admin picked in FOSSBilling, else its default: the form follows the admin area. */
    private static function pageLocale(): string
    {
        global $di;
        try {
            return (new FossbillingHost($di))->userLanguage();
        } catch (Throwable) {
            return 'en_US';
        }
    }

    /** Address of the module page in the admin area. */
    private static function modulePage(): string
    {
        global $di;
        try {
            return $di['url']->adminLink('coinspay');
        } catch (Throwable) {
            return '/admin/coinspay';
        }
    }
}
