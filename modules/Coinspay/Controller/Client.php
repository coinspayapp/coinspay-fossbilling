<?php

/**
 * Public routes of the gateway: the webhook of CoinsPay and the return of the client from the
 * payment page. Neither needs a session: the webhook is signed, the return link is signed.
 *
 * @license GPL-3.0-or-later
 */

namespace Box\Mod\Coinspay\Controller;

use Box\Mod\Coinspay\Lib\Bridge;
use Box\Mod\Coinspay\Lib\Tokens;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class Client implements \FOSSBilling\InjectionAwareInterface
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

    public function register(\Box_App &$app): void
    {
        $app->post('/coinspay/webhook', 'post_webhook', [], static::class);
        $app->get('/coinspay/return', 'get_return', [], static::class);
    }

    public function post_webhook(\Box_App $app): Response
    {
        $request = $app->getRequest();
        [$status, $text] = Bridge::webhook($this->di, (string) $request->getContent(), (string) $request->headers->get('x-cpay-signature', ''));

        return new Response($text, $status, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public function get_return(\Box_App $app): Response
    {
        // all(), not get(): get() throws on ?invoice[]=, and the client would get an error page.
        $query = $app->getRequest()->query->all();
        $target = Bridge::back($this->di, $query['invoice'] ?? null, $query[Tokens::FIELD] ?? null);

        return new RedirectResponse($target, 302, ['Cache-Control' => 'no-store']);
    }
}
