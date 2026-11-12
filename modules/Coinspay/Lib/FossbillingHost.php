<?php

namespace Box\Mod\Coinspay\Lib;

use PDO;
use Throwable;

/**
 * The module inside FOSSBilling 0.8: the only class that knows its container, models and tables.
 *
 * Reads go straight to the tables, so they are fresh within a request and the same in every
 * version; writes that FOSSBilling has logic for (funds, paying an invoice) go through its
 * services.
 */
final class FossbillingHost implements Host
{
    /** Type of the module's rows of the client balance. */
    public const BALANCE_TYPE = 'coinspay';

    private ?PDO $pdo = null;

    /**
     * @param  \Pimple\Container  $di  The container of FOSSBilling.
     * @param  int  $gatewayId  The Coinspay payment gateway, 0 when there is none.
     */
    public function __construct(private readonly \Pimple\Container $di, private readonly int $gatewayId = 0) {}

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->di['pdo'];
    }

    public function siteKey(): string
    {
        $database = '';
        try {
            $database = (string) $this->pdo()->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable) {
            // The address alone still tells the installs apart.
        }

        // Without the scheme: FOSSBilling builds SYSTEM_URL from the request, so the cron gets
        // http:// and a web request over HTTPS https://, and both must take the same locks.
        $url = defined('SYSTEM_URL') ? (string) SYSTEM_URL : '';

        return strtolower(rtrim((string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $url), '/')) . '|' . $database;
    }

    public function inTransaction(): bool
    {
        return $this->pdo()->inTransaction();
    }

    public function adminLanguage(): string
    {
        try {
            $locale = \FOSSBilling\Config::getProperty('i18n.locale', 'en_US');
        } catch (Throwable) {
            $locale = 'en_US';
        }

        return is_string($locale) ? $locale : 'en_US';
    }

    /**
     * The locale FOSSBilling picks for the page: its cookie, the browser, or the default. Read
     * the way FOSSBilling reads it, not through $di['translate'](): building a second translator
     * declares __trans() again, a fatal error in 0.8.0.
     */
    public function userLanguage(): string
    {
        try {
            $method = new \ReflectionMethod(\FOSSBilling\i18n::class, 'getActiveLocale');
            $first = $method->getParameters()[0] ?? null;
            $type = $first?->getType();
            // 0.8.9 takes the request; 0.8.0 reads the globals.
            $locale = $type instanceof \ReflectionNamedType && !$type->isBuiltin()
                ? \FOSSBilling\i18n::getActiveLocale($this->di['request'], true)
                : \FOSSBilling\i18n::getActiveLocale(true);
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        } catch (Throwable) {
            // No request here (the cron): the default.
        }

        return $this->adminLanguage();
    }

    public function isAdmin(): bool
    {
        try {
            return (bool) $this->di['auth']->isAdminLoggedIn();
        } catch (Throwable) {
            return false;
        }
    }

    public function currentClient(): ?int
    {
        try {
            if (!$this->di['auth']->isClientLoggedIn()) {
                return null;
            }
            $id = (int) $this->di['session']->get('client_id');

            return $id > 0 ? $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function invoice(int $invoiceId): ?array
    {
        $row = $this->one('SELECT id, client_id, serie, nr, hash, status, approved, currency FROM invoice WHERE id = ?', [$invoiceId]);
        if ($row === null) {
            return null;
        }
        $deposit = $this->one('SELECT 1 AS found FROM invoice_item WHERE invoice_id = ? AND type = ? LIMIT 1', [$invoiceId, 'deposit']) !== null;
        // The total as FOSSBilling counts it when paying from the balance: items, tax, discounts.
        $model = $this->di['db']->load('Invoice', $invoiceId);
        $total = $model ? $this->di['mod_service']('Invoice')->getTotalWithTax($model) : 0.0;

        return [
            'id' => (int) $row['id'],
            'number' => $this->number($row),
            'client_id' => (int) $row['client_id'],
            'status' => (string) $row['status'],
            'approved' => (bool) $row['approved'],
            'deposit' => $deposit,
            'total' => Util::formatAmount((float) $total, Decision::SCALE),
            'currency' => strtoupper((string) $row['currency']),
            'hash' => (string) $row['hash'],
        ];
    }

    public function client(int $clientId): ?array
    {
        $row = $this->one('SELECT id, currency FROM client WHERE id = ?', [$clientId]);
        if ($row === null) {
            return null;
        }
        $balance = $this->one('SELECT COALESCE(SUM(amount), 0) AS total FROM client_balance WHERE client_id = ?', [$clientId]);

        return [
            'id' => (int) $row['id'],
            'currency' => strtoupper((string) $row['currency']),
            'balance' => Util::formatAmount((string) ($balance['total'] ?? '0'), Decision::SCALE),
        ];
    }

    public function credited(int $clientId, string $reference): string
    {
        $row = $this->one(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM client_balance WHERE client_id = ? AND type = ? AND rel_id = ?',
            [$clientId, self::BALANCE_TYPE, $reference]
        );

        return Util::formatAmount((string) ($row['total'] ?? '0'), Decision::SCALE);
    }

    public function addFunds(int $clientId, string $amount, string $description, string $reference): void
    {
        $client = $this->di['db']->load('Client', $clientId);
        if (!$client || !$client->id) {
            throw new \RuntimeException('client #' . $clientId . ' is not found');
        }
        $this->di['mod_service']('Client')->addFunds($client, $amount, $description, ['type' => self::BALANCE_TYPE, 'rel_id' => $reference]);
    }

    public function payInvoice(int $invoiceId, array $references = []): bool
    {
        $service = $this->di['mod_service']('Invoice');
        $invoice = $this->di['db']->load('Invoice', $invoiceId);
        if (!$invoice || !$invoice->id || $invoice->status !== 'unpaid') {
            return false;
        }
        $clientId = (int) $invoice->client_id;
        // The lock FOSSBilling 0.8.6+ takes itself when paying from the balance; 0.8.0 takes none,
        // and two invoices of a client paid at once would both spend the same balance. The lock
        // is re-entrant on the connection, so it nests with the one of 0.8.6+.
        $lock = 'fb:credit_payment:client:' . $clientId;
        if ((string) ($this->one('SELECT GET_LOCK(?, 10) AS locked', [$lock])['locked'] ?? '') !== '1') {
            throw new \RuntimeException('the balance of client #' . $clientId . ' is busy');
        }
        try {
            $invoice = $this->di['db']->load('Invoice', $invoiceId);
            if ($invoice->status !== 'unpaid') {
                return false;
            }
            if ($service->isInvoiceTypeDeposit($invoice)) {
                // Its money is on the balance already: what came for it covers it or not.
                $paid = '0';
                foreach ($references as $reference) {
                    $paid = Util::add($paid, $this->credited($clientId, (string) $reference), Decision::SCALE);
                }
                if ((float) $paid + 0.000001 < (float) $service->getTotalWithTax($invoice)) {
                    return false;
                }
                $service->markAsPaid($invoice, true, true);
            } else {
                if (!$invoice->approved) {
                    // As the Stripe gateway of FOSSBilling: an invoice the client paid is approved.
                    $service->approveInvoice($invoice, ['use_credits' => false]);
                    $invoice = $this->di['db']->load('Invoice', $invoiceId);
                }
                $service->payInvoiceWithCredits($invoice);
            }
        } finally {
            $this->one('SELECT RELEASE_LOCK(?) AS released', [$lock]);
        }
        $now = $this->one('SELECT status FROM invoice WHERE id = ?', [$invoiceId]);

        return ($now['status'] ?? '') === 'paid';
    }

    public function recordTransaction(int $invoiceId, string $transactionId, string $amount, string $currency, string $note): void
    {
        $now = date('Y-m-d H:i:s');
        $row = $this->one('SELECT id FROM `transaction` WHERE txn_id = ? AND invoice_id = ? ORDER BY id LIMIT 1', [$transactionId, $invoiceId]);
        if ($row !== null) {
            $this->exec('UPDATE `transaction` SET amount = ?, note = ?, updated_at = ? WHERE id = ?', [$amount, $note, $now, (int) $row['id']]);

            return;
        }
        // Processed: the admin's "Process" buttons leave it alone. validate_ipn 0: nothing to check.
        $this->exec(
            'INSERT INTO `transaction` (invoice_id, gateway_id, txn_id, txn_status, amount, currency, type, status, validate_ipn, note, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)',
            [$invoiceId, $this->gatewayId ?: null, $transactionId, 'complete', $amount, $currency, 'payment', 'processed', $note, $now, $now]
        );
    }

    public function currencies(): array
    {
        return array_map(fn ($row) => strtoupper((string) $row['code']), $this->all('SELECT code FROM currency ORDER BY code', []));
    }

    public function invoiceUrl(int $invoiceId): string
    {
        return $this->di['url']->link('invoice/' . $this->hashOf($invoiceId));
    }

    public function thankYouUrl(int $invoiceId): string
    {
        return $this->di['url']->link('invoice/thank-you/' . $this->hashOf($invoiceId));
    }

    public function adminInvoiceUrl(int $invoiceId): string
    {
        return $this->di['url']->adminLink('invoice/manage/' . $invoiceId);
    }

    public function routeUrl(string $name, array $query = []): string
    {
        return $this->di['url']->link('coinspay/' . $name, $query);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        try {
            $logger = $this->di['logger'];
            $line = '[CoinsPay] ' . $message . ($context ? ' ' . Util::json($context) : '');
            match ($level) {
                'error' => $logger->error($line),
                'warning' => $logger->warning($line),
                default => $logger->info($line),
            };
        } catch (Throwable) {
            // A log FOSSBilling cannot write must not stop a payment.
        }
    }

    /** When the cron of FOSSBilling ran last, as FOSSBilling keeps it (its time zone), or ''. */
    public function lastCron(): string
    {
        $row = $this->one('SELECT value FROM setting WHERE param = ?', ['last_cron_exec']);

        return (string) ($row['value'] ?? '');
    }

    /** Whether the hooks of the module are connected: FOSSBilling connects them on activation and on every cron run. */
    public function hooksConnected(): bool
    {
        return $this->one(
            'SELECT 1 AS found FROM extension_meta WHERE extension = ? AND rel_type = ? AND rel_id = ? AND meta_key = ? LIMIT 1',
            ['mod_hook', 'mod', 'coinspay', 'listener']
        ) !== null;
    }

    /** Invoice number as the client sees it: series and number, padded as FOSSBilling pads it. */
    private function number(array $row): string
    {
        $nr = is_numeric($row['nr'] ?? null) ? (int) $row['nr'] : (int) $row['id'];
        $setting = $this->one('SELECT value FROM setting WHERE param = ?', ['invoice_number_padding']);
        $padding = isset($setting['value']) && $setting['value'] !== '' ? max(0, (int) $setting['value']) : 5;

        return (string) ($row['serie'] ?? '') . sprintf('%0' . $padding . 's', $nr);
    }

    private function hashOf(int $invoiceId): string
    {
        $row = $this->one('SELECT hash FROM invoice WHERE id = ?', [$invoiceId]);

        return (string) ($row['hash'] ?? '');
    }

    private function exec(string $sql, array $args): void
    {
        $statement = $this->pdo()->prepare($sql);
        Repo::run($statement, $args);
        $statement->closeCursor();
    }

    private function one(string $sql, array $args): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        Repo::run($statement, $args);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $row ?: null;
    }

    private function all(string $sql, array $args): array
    {
        $statement = $this->pdo()->prepare($sql);
        Repo::run($statement, $args);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $rows;
    }
}
