<?php

namespace Box\Mod\Coinspay\Lib;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Tables of the module: CoinsPay invoices of FOSSBilling invoices, and a small key-value store.
 * Plain SQL through the PDO of FOSSBilling.
 */
class Repo
{
    public const INVOICES = 'coinspay_invoices';

    public const KV = 'coinspay_kv';

    private ?bool $ready = null;

    public function __construct(private readonly Host $host) {}

    /**
     * Creates the tables: on activation of the module, and before the first payment. CREATE TABLE
     * commits an open transaction of the connection, so ensure() skips it inside one.
     *
     * `attention` — a payment that needs a look of the admin; `problem` — a payment received but
     * not added or not paid yet, cleared when a retry goes through.
     */
    public function install(): void
    {
        $this->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::INVOICES . '` (
                `invoice_id` CHAR(36) NOT NULL,
                `fb_invoice` BIGINT UNSIGNED NOT NULL,
                `fb_client` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `attempt` INT UNSIGNED NOT NULL,
                `amount` VARCHAR(40) NOT NULL,
                `due` VARCHAR(40) NOT NULL DEFAULT \'\',
                `currency` VARCHAR(10) NOT NULL,
                `test` TINYINT(1) NOT NULL DEFAULT 0,
                `test_admin` TINYINT(1) NOT NULL DEFAULT 0,
                `status` VARCHAR(16) NOT NULL DEFAULT \'open\',
                `pay_url` VARCHAR(500) NOT NULL DEFAULT \'\',
                `snapshot` TEXT NULL,
                `fingerprint` CHAR(32) NULL,
                `apply_state` VARCHAR(10) NOT NULL DEFAULT \'none\',
                `claimed` INT UNSIGNED NULL,
                `attention` TEXT NULL,
                `problem` TEXT NULL,
                `next_poll` INT UNSIGNED NULL,
                `poll_until` INT UNSIGNED NOT NULL DEFAULT 0,
                `created` INT UNSIGNED NOT NULL,
                `updated` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`invoice_id`),
                KEY `fb_invoice` (`fb_invoice`),
                KEY `next_poll` (`next_poll`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            []
        );
        $this->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::KV . '` (
                `k` VARCHAR(64) NOT NULL,
                `v` TEXT NOT NULL,
                `updated` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`k`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            []
        );
        $this->ready = true;
    }

    /** The tables, when they are missing and no transaction is open. */
    public function ensure(): bool
    {
        if (!$this->ready() && !$this->host->inTransaction()) {
            $this->install();
        }

        return $this->ready();
    }

    /** Whether the tables exist: before the module is activated they may not. */
    public function ready(): bool
    {
        if ($this->ready === null) {
            $row = $this->one('SELECT 1 AS found FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [self::INVOICES]);
            $this->ready = $row !== null;
        }

        return $this->ready;
    }

    /** @param  array  $row  Columns of a new CoinsPay invoice. */
    public function add(array $row): void
    {
        $now = time();
        $row += ['created' => $now, 'updated' => $now, 'apply_state' => 'none'];
        $columns = array_keys($row);
        $this->exec(
            'INSERT INTO `' . self::INVOICES . '` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
            array_values($row)
        );
    }

    public function find(string $invoiceId): ?array
    {
        if (!$this->ready()) {
            return null;
        }

        return $this->one('SELECT * FROM `' . self::INVOICES . '` WHERE invoice_id = ?', [strtolower($invoiceId)]);
    }

    /** CoinsPay invoices of a FOSSBilling invoice, the newest first. */
    public function forInvoice(int $fbInvoice): array
    {
        if (!$this->ready()) {
            return [];
        }

        return $this->all('SELECT * FROM `' . self::INVOICES . '` WHERE fb_invoice = ? ORDER BY attempt DESC', [$fbInvoice]);
    }

    /** The newest CoinsPay invoice of a FOSSBilling invoice: the one the client pays now. */
    public function current(int $fbInvoice): ?array
    {
        $rows = $this->forInvoice($fbInvoice);

        return $rows[0] ?? null;
    }

    public function update(string $invoiceId, array $columns): void
    {
        $columns['updated'] = time();
        $sets = [];
        foreach (array_keys($columns) as $column) {
            $sets[] = '`' . $column . '` = ?';
        }
        $args = array_values($columns);
        $args[] = strtolower($invoiceId);
        $this->exec('UPDATE `' . self::INVOICES . '` SET ' . implode(', ', $sets) . ' WHERE invoice_id = ?', $args);
    }

    /** Invoices to poll now: open ones, and closed ones a little longer for a late payment. */
    public function due(int $limit): array
    {
        if (!$this->ready()) {
            return [];
        }
        $now = time();

        return $this->all(
            'SELECT * FROM `' . self::INVOICES . '` WHERE next_poll IS NOT NULL AND next_poll <= ? AND poll_until > ? ORDER BY next_poll LIMIT ' . max(1, $limit),
            [$now, $now]
        );
    }

    /** CoinsPay invoices with money that need a look of the admin, the newest first. */
    public function attention(int $limit, int $since): array
    {
        if (!$this->ready()) {
            return [];
        }

        return $this->all(
            'SELECT * FROM `' . self::INVOICES . '` WHERE (attention IS NOT NULL OR problem IS NOT NULL) AND updated >= ? ORDER BY updated DESC LIMIT ' . max(1, $limit),
            [$since]
        );
    }

    public function kvGet(string $key, int $maxAge = 0): ?string
    {
        if (!$this->ready()) {
            return null;
        }
        $row = $this->one('SELECT v, updated FROM `' . self::KV . '` WHERE k = ?', [$key]);
        if (!$row || ($maxAge > 0 && (int) $row['updated'] < time() - $maxAge)) {
            return null;
        }

        return (string) $row['v'];
    }

    public function kvSet(string $key, string $value): void
    {
        if (!$this->ready()) {
            return;
        }
        $this->exec(
            'INSERT INTO `' . self::KV . '` (k, v, updated) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated = VALUES(updated)',
            [$key, $value, time()]
        );
    }

    /**
     * Whether this request may do a periodic job now: one request takes it, the others skip.
     *
     * @param  int  $every  Seconds.
     */
    public function takeTurn(string $key, int $every): bool
    {
        if (!$this->ready()) {
            return false;
        }
        $now = time();
        $this->exec('INSERT IGNORE INTO `' . self::KV . '` (k, v, updated) VALUES (?, \'\', 0)', [$key]);
        $statement = $this->host->pdo()->prepare('UPDATE `' . self::KV . '` SET updated = ? WHERE k = ? AND updated <= ?');
        self::run($statement, [$now, $key, $now - $every]);

        return $statement->rowCount() === 1;
    }

    /**
     * Claims the payment of a CoinsPay invoice for this request: one row update, so of two
     * requests at once only one gets it, whatever the named lock did. A claim left by a request
     * that died is taken over after $stale seconds.
     */
    public function claim(string $invoiceId, int $stale): bool
    {
        $now = time();
        $statement = $this->host->pdo()->prepare(
            'UPDATE `' . self::INVOICES . '` SET apply_state = \'applying\', claimed = ?, updated = ?
            WHERE invoice_id = ? AND (apply_state <> \'applying\' OR claimed IS NULL OR claimed < ?)'
        );
        self::run($statement, [$now, $now, strtolower($invoiceId), $now - $stale]);

        return $statement->rowCount() === 1;
    }

    /**
     * Takes the lock of a FOSSBilling invoice: requests of one invoice go in turn. claim() is the
     * guard of the payment itself.
     *
     * @return bool False when another request held it for 10 seconds.
     */
    public function lock(int $fbInvoice): bool
    {
        $row = $this->one('SELECT GET_LOCK(?, 10) AS locked', [$this->lockName($fbInvoice)]);

        return $row !== null && (string) $row['locked'] === '1';
    }

    public function unlock(int $fbInvoice): void
    {
        $this->one('SELECT RELEASE_LOCK(?) AS released', [$this->lockName($fbInvoice)]);
    }

    /** Runs a prepared statement; a failed query stops the module even in the silent error mode. */
    public static function run(PDOStatement $statement, array $args): void
    {
        if ($statement->execute($args) === false) {
            $info = $statement->errorInfo();

            throw new RuntimeException('Database query failed: ' . ($info[2] ?? 'unknown error'));
        }
    }

    private function lockName(int $fbInvoice): string
    {
        // Not from the address of the request: a webhook, the cron and a client with another Host
        // header must take the same lock.
        return 'coinspay_fb_' . substr(md5($this->host->siteKey()), 0, 12) . '_' . $fbInvoice;
    }

    private function exec(string $sql, array $args): void
    {
        $statement = $this->host->pdo()->prepare($sql);
        self::run($statement, $args);
        $statement->closeCursor();
    }

    private function one(string $sql, array $args): ?array
    {
        $statement = $this->host->pdo()->prepare($sql);
        self::run($statement, $args);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $row ?: null;
    }

    private function all(string $sql, array $args): array
    {
        $statement = $this->host->pdo()->prepare($sql);
        self::run($statement, $args);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $rows;
    }
}
