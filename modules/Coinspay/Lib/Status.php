<?php

namespace Box\Mod\Coinspay\Lib;

/**
 * The "Status" block of the module page: the shop of the key, the webhook address, the cron and
 * the payments that need the admin. The answer of the API is kept for a minute.
 */
final class Status
{
    public const CACHE = 60;

    /** Payments that need the admin are listed for this long. */
    public const ATTENTION_DAYS = 30;

    public function __construct(private readonly Module $module) {}

    /**
     * @param  array  $environment  Lines about FOSSBilling itself (the gateway, the hooks, the
     *                              cron), shown after the webhook address.
     * @return array Lines: {ok: true|false|null, text, link?}. ok null — plain information.
     */
    public function lines(array $environment = []): array
    {
        $module = $this->module;
        $settings = $module->settings();
        $text = $module->adminText();
        $lines = [];

        $repo = $module->repo();
        $repo->ensure();

        $lines[] = ['ok' => null, 'text' => $text->t('status_webhook', $module->webhookUrl())];
        foreach ($environment as $line) {
            $lines[] = $line;
        }
        if ($settings->apiKey() === '') {
            $lines[] = ['ok' => false, 'text' => $text->t('status_no_key')];

            return $lines;
        }

        $shop = $this->shop();
        if (isset($shop['error'])) {
            $lines[] = ['ok' => false, 'text' => $text->t('status_api_error', $shop['error'])];
        } else {
            $test = !empty($shop['key']['test']);
            $lines[] = ['ok' => true, 'text' => $text->t('status_shop', Util::value($shop['shop'] ?? [], 'name'), $text->t($test ? 'status_mode_test' : 'status_mode_live'))];
            if (Util::value($shop['key'] ?? [], 'access') === 'full') {
                $lines[] = ['ok' => false, 'text' => $text->t('status_full_access')];
            }
            foreach ($module->host()->currencies() as $code) {
                if (!$module->currencies()->accepts($code)) {
                    $lines[] = ['ok' => false, 'text' => $text->t('status_currency_no', $code)];
                }
            }
        }

        if ($settings->webhookSecret() === '') {
            $lines[] = ['ok' => false, 'text' => $text->t('status_no_secret')];
        } else {
            $seen = $repo->kvGet('webhook_test');
            $lines[] = $seen !== null && (int) $seen > 0
                ? ['ok' => true, 'text' => $text->t('status_webhook_seen', gmdate('Y-m-d H:i', (int) $seen) . ' UTC')]
                : ['ok' => null, 'text' => $text->t('status_webhook_unseen')];
        }

        $attention = $repo->attention(10, time() - self::ATTENTION_DAYS * 86400);
        if ($attention) {
            $lines[] = ['ok' => false, 'text' => $text->t('status_attention', self::ATTENTION_DAYS)];
            foreach ($attention as $row) {
                $lines[] = [
                    'ok' => null,
                    'text' => sprintf('#%d — %s', $row['fb_invoice'], trim(($row['problem'] ?? '') . ' ' . ($row['attention'] ?? ''))),
                    'link' => $module->host()->adminInvoiceUrl((int) $row['fb_invoice']),
                ];
            }
        }

        return $lines;
    }

    /** The lines as HTML for the module page. */
    public function html(array $environment = []): string
    {
        $out = '';
        foreach ($this->lines($environment) as $line) {
            $mark = $line['ok'] === true ? "\u{2714} " : ($line['ok'] === false ? "\u{2718} " : '');
            $body = Util::html($mark . $line['text']);
            if (isset($line['link'])) {
                $body = '<a href="' . Util::html($line['link']) . '" target="_blank" rel="noopener" style="text-decoration:underline">' . $body . '</a>';
            }
            $out .= '<div style="margin:2px 0">' . $body . '</div>';
        }

        return $out;
    }

    /** GET /shop, or {error}; kept for a minute per key. */
    private function shop(): array
    {
        $repo = $this->module->repo();
        $settings = $this->module->settings();
        $key = 'shop_' . substr(hash('sha256', $settings->apiUrl() . '|' . $settings->apiKey()), 0, 16);
        $cached = $repo->kvGet($key, self::CACHE);
        if ($cached !== null) {
            $data = json_decode($cached, true);
            if (is_array($data)) {
                return $data;
            }
        }
        try {
            $data = $this->module->client()->shop();
        } catch (ApiError $e) {
            $data = ['error' => $e->summary()];
        }
        $repo->kvSet($key, Util::json($data));

        return $data;
    }
}
