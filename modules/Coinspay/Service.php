<?php

/**
 * CoinsPay for FOSSBilling — the module half of the gateway.
 *
 * The payment adapter of FOSSBilling (library/Payment/Adapter/Coinspay) cannot listen to events
 * or run with the cron. The module does it: it creates the tables of the gateway when activated,
 * cancels CoinsPay invoices that an admin's change made unwanted, and polls open CoinsPay invoices
 * on every run of the cron. Its controllers take the webhook and the return of the client.
 *
 * @license GPL-3.0-or-later
 */

namespace Box\Mod\Coinspay;

use Box\Mod\Coinspay\Lib\Bridge;
use Box\Mod\Coinspay\Lib\FossbillingHost;
use Box\Mod\Coinspay\Lib\Repo;

class Service implements \FOSSBilling\InjectionAwareInterface
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

    /** Activation of the module: the tables of the gateway. */
    public function install(): bool
    {
        (new Repo(new FossbillingHost($this->di)))->install();

        return true;
    }

    /** The tables stay: they tell which payments are added already. */
    public function uninstall(): bool
    {
        return true;
    }

    /** A new version of the module: the tables, should they be missing. */
    public function update(mixed $manifest = null): bool
    {
        (new Repo(new FossbillingHost($this->di)))->install();

        return true;
    }

    /** An admin changed an invoice: its status, items or client. */
    public static function onAfterAdminInvoiceUpdate(\Box_Event $event): void
    {
        $params = $event->getParameters();
        Bridge::invoiceChanged($event->getDi(), (int) ($params['id'] ?? 0));
    }

    /** The invoice got paid, by another gateway or by hand: CoinsPay invoices of it are not wanted. */
    public static function onAfterAdminInvoicePaymentReceived(\Box_Event $event): void
    {
        $params = $event->getParameters();
        Bridge::invoiceChanged($event->getDi(), (int) ($params['id'] ?? 0));
    }

    /** An admin deletes an invoice. */
    public static function onBeforeAdminInvoiceDelete(\Box_Event $event): void
    {
        $params = $event->getParameters();
        Bridge::invoiceDeleting($event->getDi(), (int) ($params['id'] ?? 0));
    }

    /** Every run of the cron of FOSSBilling: polling of CoinsPay invoices. */
    public static function onAfterAdminCronRun(\Box_Event $event): void
    {
        Bridge::cron($event->getDi());
    }
}
