<?php

/**
 * The page of the module in the admin area: the connection check, the webhook address, the cron
 * and the payments that need a look of the admin.
 *
 * @license GPL-3.0-or-later
 */

namespace Box\Mod\Coinspay\Controller;

use Box\Mod\Coinspay\Lib\Bridge;
use Box\Mod\Coinspay\Lib\FossbillingHost;
use Box\Mod\Coinspay\Lib\Settings;
use Box\Mod\Coinspay\Lib\Text;

class Admin implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function fetchNavigation(): array
    {
        return [
            'subpages' => [
                [
                    'location' => 'extensions',
                    'label' => 'CoinsPay',
                    'index' => 1600,
                    'uri' => $this->di['url']->adminLink('coinspay'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/coinspay', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];
        $text = new Text((new FossbillingHost($this->di))->adminLanguage());

        return $app->render('mod_coinspay_index', [
            'coinspay_title' => $text->t('status_title'),
            'coinspay_status' => Bridge::statusHtml($this->di),
            'coinspay_version' => Settings::VERSION,
        ]);
    }
}
