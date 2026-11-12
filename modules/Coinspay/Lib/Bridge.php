<?php

namespace Box\Mod\Coinspay\Lib;

use Throwable;

/**
 * Where FOSSBilling meets the module: the gateway record, the webhook and the return of the
 * client, the hooks of invoices and the cron, and the status page. The controllers, the hooks of
 * Service.php and the payment adapter call it; it only needs the container of FOSSBilling.
 */
final class Bridge
{
    /** The name FOSSBilling keeps for the gateway: the folder of the adapter. */
    public const GATEWAY = 'Coinspay';

    /** Seconds of an API request inside a page the client or admin waits for. */
    public const PAGE_TIMEOUT = 8;

    /** Without a run for this long the cron counts as stopped: FOSSBilling runs it every 5 minutes. */
    public const CRON_LATE = 900;

    /**
     * The return link asks the API about the invoice at most this often: the link never expires,
     * and its hits must not use up the API limit of the shop.
     */
    public const RETURN_EVERY = 15;

    /**
     * The Coinspay gateway: the enabled one first, when the admin made copies.
     *
     * @return array|null {id, enabled, config}
     */
    public static function gateway(\Pimple\Container $di): ?array
    {
        $statement = $di['pdo']->prepare('SELECT id, enabled, config FROM pay_gateway WHERE gateway = ? ORDER BY enabled DESC, id LIMIT 1');
        Repo::run($statement, [self::GATEWAY]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $statement->closeCursor();
        if (!$row) {
            return null;
        }
        $config = json_decode((string) ($row['config'] ?? ''), true);

        return ['id' => (int) $row['id'], 'enabled' => (bool) $row['enabled'], 'config' => is_array($config) ? $config : []];
    }

    /** The module with the settings of the gateway; inactive without an enabled gateway. */
    public static function module(\Pimple\Container $di, int $timeout = ApiClient::TIMEOUT): Module
    {
        $gateway = self::gateway($di);

        return new Module(new FossbillingHost($di, $gateway['id'] ?? 0), $gateway['config'] ?? [], $gateway !== null && $gateway['enabled'], $timeout);
    }

    /**
     * POST /coinspay/webhook.
     *
     * @return array {0: HTTP status, 1: text}
     */
    public static function webhook(\Pimple\Container $di, string $body, string $signature): array
    {
        $module = null;
        try {
            $module = self::module($di);

            return $module->webhook()->handle($body, $signature);
        } catch (Throwable $e) {
            $module?->host()->log('error', 'webhook: ' . $e->getMessage());

            return [500, 'the invoice was not applied'];
        }
    }

    /**
     * GET /coinspay/return: the client back from the payment page. The state of the CoinsPay
     * invoice is read at once, so a paid invoice shows paid.
     *
     * @return string Where to send the client.
     */
    public static function back(\Pimple\Container $di, mixed $invoiceId, mixed $signature): string
    {
        $id = is_string($invoiceId) && ctype_digit($invoiceId) ? (int) $invoiceId : 0;
        $module = self::module($di, self::PAGE_TIMEOUT);
        $host = $module->host();
        if ($id <= 0 || !is_string($signature) || !$module->tokens()->checkReturn($id, $signature) || $host->invoice($id) === null) {
            return defined('SYSTEM_URL') ? SYSTEM_URL : '/';
        }
        $row = null;
        self::safely($module, 'return', function () use ($module, $id, &$row): void {
            $row = $module->repo()->current($id);
            if ($row && (bool) $row['test'] === $module->settings()->isTestMode() && $module->repo()->takeTurn('return_' . $id, self::RETURN_EVERY)) {
                $module->sync()->apply($module->client()->invoice($row['invoice_id']));
                $row = $module->repo()->find($row['invoice_id']);
            }
        });
        $invoice = $host->invoice($id);
        if ($invoice !== null && $invoice['status'] === 'unpaid' && $row) {
            // Paid but not confirmed yet: the page of FOSSBilling that says so.
            $snapshot = json_decode((string) ($row['snapshot'] ?? ''), true);
            $snapshot = is_array($snapshot) ? $snapshot : [];
            if (Util::positive(Util::value($snapshot, 'amount_pending')) || Util::positive(Util::value($snapshot, 'amount_paid'))) {
                return $host->thankYouUrl($id);
            }
        }

        return $host->invoiceUrl($id);
    }

    /**
     * An admin changed an invoice: its open CoinsPay invoices that no longer fit it (cancelled,
     * paid otherwise, another amount or client) are cancelled, if they hold no money.
     */
    public static function invoiceChanged(\Pimple\Container $di, int $fbInvoice): void
    {
        if (Sync::$applying || $fbInvoice <= 0) {
            return;
        }
        $module = self::module($di, self::PAGE_TIMEOUT);
        self::safely($module, 'invoice #' . $fbInvoice . ' changed', fn () => $module->repo()->ready() && $module->poller()->reconsider($fbInvoice));
    }

    /** An admin deletes an invoice: its open CoinsPay invoices without money are cancelled. */
    public static function invoiceDeleting(\Pimple\Container $di, int $fbInvoice): void
    {
        if ($fbInvoice <= 0) {
            return;
        }
        $module = self::module($di, self::PAGE_TIMEOUT);
        // Still there for a moment: money found now goes to the balance, not to the invoice.
        Sync::$deleting[$fbInvoice] = true;
        try {
            self::safely($module, 'invoice #' . $fbInvoice . ' deleted', function () use ($module, $fbInvoice): void {
                if ($module->repo()->ready()) {
                    $module->payment()->cancelReplaced($fbInvoice);
                }
            });
        } finally {
            unset(Sync::$deleting[$fbInvoice]);
        }
    }

    /**
     * A transaction of the gateway that FOSSBilling processes: a hit of ipn.php, or the admin's
     * "Process". It carries nothing the module trusts: the newest CoinsPay invoice of its invoice
     * is read from the API (at most once a minute), and the row is closed as processed.
     */
    public static function transaction(\Pimple\Container $di, int $transactionId): void
    {
        $module = self::module($di, self::PAGE_TIMEOUT);
        $statement = $di['pdo']->prepare('SELECT invoice_id FROM `transaction` WHERE id = ?');
        Repo::run($statement, [$transactionId]);
        $invoiceId = (int) $statement->fetchColumn();
        $statement->closeCursor();
        if ($invoiceId > 0) {
            self::safely($module, 'transaction #' . $transactionId, function () use ($module, $invoiceId): void {
                if ($module->repo()->ready() && $module->settings()->apiKey() !== '') {
                    $module->poller()->checkInvoice($invoiceId);
                }
            });
        }
        // The note of a transaction of the module itself stays.
        $note = $module->adminText()->t('transaction_ignored');
        $statement = $di['pdo']->prepare("UPDATE `transaction` SET status = ?, error = NULL, error_code = NULL, note = CASE WHEN note IS NULL OR note = '' THEN ? ELSE note END, updated_at = ? WHERE id = ?");
        Repo::run($statement, ['processed', $note, date('Y-m-d H:i:s'), $transactionId]);
    }

    /** A run of the FOSSBilling cron: polling of open and recently closed CoinsPay invoices. */
    public static function cron(\Pimple\Container $di): void
    {
        $module = self::module($di);
        self::safely($module, 'cron', function () use ($module): void {
            if ($module->repo()->ensure() && $module->settings()->apiKey() !== '') {
                $module->poller()->run();
            }
        });
    }

    /** The status block of the module page, as HTML. */
    public static function statusHtml(\Pimple\Container $di): string
    {
        $module = self::module($di, self::PAGE_TIMEOUT);
        $host = $module->host();
        $text = $module->pageText();
        $environment = [];

        $gateway = self::gateway($di);
        if ($gateway === null) {
            $environment[] = ['ok' => false, 'text' => $text->t('status_no_gateway')];
        } elseif (!$gateway['enabled']) {
            $environment[] = ['ok' => false, 'text' => $text->t('status_gateway_off')];
        }

        if ($host instanceof FossbillingHost) {
            if (!$host->hooksConnected()) {
                // FOSSBilling connects hooks on activation and on every cron run; once more here.
                try {
                    $di['mod_service']('hook')->batchConnect('coinspay');
                } catch (Throwable $e) {
                    $host->log('warning', 'connecting the hooks: ' . $e->getMessage());
                }
            }
            $environment[] = $host->hooksConnected()
                ? ['ok' => true, 'text' => $text->t('status_hooks_connected')]
                : ['ok' => false, 'text' => $text->t('status_hooks_missing')];

            $cron = $host->lastCron();
            $at = $cron !== '' ? strtotime($cron) : false;
            if ($at === false) {
                $environment[] = ['ok' => false, 'text' => $text->t('status_cron_never')];
            } elseif ($at < time() - self::CRON_LATE) {
                $environment[] = ['ok' => false, 'text' => $text->t('status_cron_late', $cron)];
            } else {
                $environment[] = ['ok' => true, 'text' => $text->t('status_cron_last', $cron)];
            }
        }

        try {
            return (new Status($module))->html($environment);
        } catch (Throwable $e) {
            $host->log('error', 'status: ' . $e->getMessage());

            return '<div>' . Util::html($text->t('status_api_error', $e->getMessage())) . '</div>';
        }
    }

    /** Runs a step whose failure must not break the page or the cron of FOSSBilling. */
    private static function safely(Module $module, string $what, callable $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            $module->host()->log('error', $what . ': ' . $e->getMessage());
        }
    }
}
