<?php

/**
 * CoinsPay for FOSSBilling — the module half of the gateway.
 *
 * The payment adapter of FOSSBilling (library/Payment/Adapter/Coinspay) cannot listen to events
 * or run with the cron. The module does it: when activated it writes the adapter from its Gateway
 * folder and creates the tables of the gateway, cancels CoinsPay invoices that an admin's change
 * made unwanted, and polls open CoinsPay invoices on every run of the cron. Its controllers take
 * the webhook and the return of the client.
 *
 * @license GPL-3.0-or-later
 */

namespace Box\Mod\Coinspay;

use Box\Mod\Coinspay\Lib\Bridge;
use Box\Mod\Coinspay\Lib\FossbillingHost;
use Box\Mod\Coinspay\Lib\GatewayFiles;
use Box\Mod\Coinspay\Lib\GatewayFilesError;
use Box\Mod\Coinspay\Lib\Repo;
use Box\Mod\Coinspay\Lib\Settings;
use Box\Mod\Coinspay\Lib\Text;

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

    /** Activation of the module: the payment adapter and the tables of the gateway. */
    public function install(): bool
    {
        $this->writeGateway();
        (new Repo(new FossbillingHost($this->di)))->install();

        return true;
    }

    /**
     * The tables stay: they tell which payments are added already. The adapter stays too: invoices
     * and transactions of FOSSBilling refer to the gateway.
     */
    public function uninstall(): bool
    {
        return true;
    }

    /** A new version of the module: its adapter, and the tables should they be missing. */
    public function update(mixed $manifest = null): bool
    {
        $this->writeGateway();
        (new Repo(new FossbillingHost($this->di)))->install();

        return true;
    }

    /**
     * The extension directory of FOSSBilling installs modules/Coinspay alone: the module writes the
     * adapter into library/Payment/Adapter/Coinspay, where FOSSBilling looks for gateways.
     */
    private function writeGateway(): void
    {
        $host = new FossbillingHost($this->di);
        try {
            $found = GatewayFiles::forFossbilling()->install();
        } catch (GatewayFilesError $e) {
            throw new \FOSSBilling\InformationException((new Text($host->userLanguage()))->t($e->getMessage(), GatewayFiles::SHOWN));
        }
        if ($found !== GatewayFiles::CURRENT) {
            $host->log('info', GatewayFiles::SHOWN . ' written (' . $found . '), version ' . Settings::VERSION);
        }
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
