<?php

use Box\Mod\Coinspay\Lib\ApiClient;
use Box\Mod\Coinspay\Lib\ApiError;
use Box\Mod\Coinspay\Lib\Host;
use Box\Mod\Coinspay\Lib\Module;
use Box\Mod\Coinspay\Lib\Repo;

/**
 * FOSSBilling for unit tests: invoices, clients and the rows of the client balance in arrays. A
 * payment adds a row of the balance, paying an invoice takes a negative row, as FOSSBilling does.
 * addFunds() can fail or claim success without adding anything, as asked.
 */
final class FakeHost implements Host
{
    public array $settings = ['api_url' => 'https://pay.example/api/v1', 'api_key' => 'cpk_live_key', 'webhook_secret' => 'whsec_test'];

    /** Invoices by id. */
    public array $invoices = [42 => ['id' => 42, 'number' => 'FOSS00042', 'client_id' => 7, 'status' => 'unpaid', 'approved' => true, 'deposit' => false, 'total' => '100.00', 'currency' => 'USD', 'hash' => 'h42']];

    /** Clients by id. */
    public array $clients = [7 => ['id' => 7, 'currency' => 'USD']];

    /** Rows of the client balance: {client_id, type, rel_id, amount, description}. */
    public array $balance = [];

    /** Transaction rows shown to the admin: transaction id → {invoice, amount, currency}. */
    public array $transactions = [];

    /** Every addFunds() call: [client, amount, row id]. */
    public array $funds = [];

    /** Invoices payInvoice() was called for. */
    public array $payCalls = [];

    public array $log = [];

    public bool $admin = false;

    public string $locale = 'en';

    /** "throw" — fail before adding; "throw_after" — add, then fail; "silent" — add nothing. */
    public ?string $fail = null;

    /** "throw" — paying from the balance fails. */
    public ?string $payFail = null;

    /** A database transaction of FOSSBilling is open. */
    public bool $inTx = false;

    /** Called before every read of an invoice with the number of the read: a change by FOSSBilling. */
    public ?Closure $onRead = null;

    private int $reads = 0;

    public function pdo(): PDO
    {
        throw new LogicException('no database in unit tests');
    }

    public function siteKey(): string
    {
        return 'https://billing.example/|fossbilling';
    }

    public function inTransaction(): bool
    {
        return $this->inTx;
    }

    public function adminLanguage(): string
    {
        return 'en_US';
    }

    public function userLanguage(): string
    {
        return $this->locale;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    /** The client signed in on the page. */
    public ?int $signedIn = 7;

    public function currentClient(): ?int
    {
        return $this->signedIn;
    }

    public function invoice(int $invoiceId): ?array
    {
        if ($this->onRead) {
            ($this->onRead)(++$this->reads, $this);
        }

        return $this->invoices[$invoiceId] ?? null;
    }

    public function client(int $clientId): ?array
    {
        if (!isset($this->clients[$clientId])) {
            return null;
        }

        return $this->clients[$clientId] + ['balance' => $this->balanceOf($clientId)];
    }

    public function balanceOf(int $clientId): string
    {
        $sum = 0.0;
        foreach ($this->balance as $row) {
            if ($row['client_id'] === $clientId) {
                $sum += (float) $row['amount'];
            }
        }

        return number_format($sum, 2, '.', '');
    }

    public function credited(int $clientId, string $reference): string
    {
        $sum = 0.0;
        foreach ($this->balance as $row) {
            if ($row['client_id'] === $clientId && $row['type'] === 'coinspay' && $row['rel_id'] === $reference) {
                $sum += (float) $row['amount'];
            }
        }

        return number_format($sum, 2, '.', '');
    }

    public function addFunds(int $clientId, string $amount, string $description, string $reference): void
    {
        $fail = $this->fail;
        $this->fail = null;
        $this->funds[] = [$clientId, $amount, $reference];
        if ($fail === 'throw') {
            throw new RuntimeException('the balance could not be saved');
        }
        if ($fail !== 'silent') {
            $this->balance[] = ['client_id' => $clientId, 'type' => 'coinspay', 'rel_id' => $reference, 'amount' => $amount, 'description' => $description];
        }
        if ($fail === 'throw_after') {
            throw new RuntimeException('a hook of another extension failed');
        }
    }

    public function payInvoice(int $invoiceId, array $references = []): bool
    {
        $this->payCalls[] = $invoiceId;
        if ($this->payFail === 'throw') {
            $this->payFail = null;

            throw new RuntimeException('paying from the balance failed');
        }
        $invoice = $this->invoices[$invoiceId] ?? null;
        if (!$invoice || $invoice['status'] !== 'unpaid') {
            return false;
        }
        $client = (int) $invoice['client_id'];
        if (!empty($invoice['deposit'])) {
            // As FossbillingHost: the money of an "add funds" invoice is the balance itself.
            $brought = 0.0;
            foreach ($references as $reference) {
                $brought += (float) $this->credited($client, (string) $reference);
            }
            if ($brought + 0.000001 < (float) $invoice['total']) {
                return false;
            }
        } else {
            if ((float) $this->balanceOf($client) + 0.000001 < (float) $invoice['total']) {
                return false;
            }
            $this->balance[] = ['client_id' => $client, 'type' => 'invoice', 'rel_id' => (string) $invoiceId, 'amount' => '-' . $invoice['total'], 'description' => 'Payment for invoice'];
        }
        $this->invoices[$invoiceId]['status'] = 'paid';

        return true;
    }

    public function recordTransaction(int $invoiceId, string $transactionId, string $amount, string $currency, string $note): void
    {
        $this->transactions[$transactionId] = ['invoice' => $invoiceId, 'amount' => $amount, 'currency' => $currency];
    }

    public function currencies(): array
    {
        return ['USD', 'EUR'];
    }

    public function invoiceUrl(int $invoiceId): string
    {
        return 'https://billing.example/invoice/' . ($this->invoices[$invoiceId]['hash'] ?? '');
    }

    public function thankYouUrl(int $invoiceId): string
    {
        return 'https://billing.example/invoice/thank-you/' . ($this->invoices[$invoiceId]['hash'] ?? '');
    }

    public function adminInvoiceUrl(int $invoiceId): string
    {
        return 'https://billing.example/admin/invoice/manage/' . $invoiceId;
    }

    public function routeUrl(string $name, array $query = []): string
    {
        $url = 'https://billing.example/coinspay/' . $name;

        return $query ? $url . '?' . http_build_query($query, '', '&') : $url;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->log[] = [$level, $message];
    }

    /** Messages of a level. */
    public function logged(string $level): array
    {
        return array_values(array_map(fn ($line) => $line[1], array_filter($this->log, fn ($line) => $line[0] === $level)));
    }

    /** What the module added for the invoice, all rows together. */
    public function coinspayTotal(): string
    {
        $sum = 0.0;
        foreach ($this->balance as $row) {
            if ($row['type'] === 'coinspay') {
                $sum += (float) $row['amount'];
            }
        }

        return number_format($sum, 2, '.', '');
    }
}

/** The tables of the module in memory. */
final class MemoryRepo extends Repo
{
    public array $rows = [];

    public array $kv = [];

    public array $turns = [];

    public function __construct() {}

    public function install(): void {}

    public function ready(): bool
    {
        return true;
    }

    public function add(array $row): void
    {
        $this->rows[$row['invoice_id']] = $row + ['due' => number_format((float) $row['amount'], 2, '.', ''), 'fb_client' => 7, 'apply_state' => 'none', 'claimed' => null, 'fingerprint' => null, 'attention' => null, 'problem' => null, 'snapshot' => null, 'updated' => time()];
    }

    public function find(string $invoiceId): ?array
    {
        return $this->rows[strtolower($invoiceId)] ?? null;
    }

    public function forInvoice(int $fbInvoice): array
    {
        $rows = array_values(array_filter($this->rows, fn ($row) => (int) $row['fb_invoice'] === $fbInvoice));
        usort($rows, fn ($a, $b) => $b['attempt'] <=> $a['attempt']);

        return $rows;
    }

    public function update(string $invoiceId, array $columns): void
    {
        $columns['updated'] = time();
        $this->rows[strtolower($invoiceId)] = $columns + $this->rows[strtolower($invoiceId)];
    }

    public function due(int $limit): array
    {
        $now = time();

        return array_slice(array_values(array_filter($this->rows, fn ($row) => $row['next_poll'] !== null && $row['next_poll'] <= $now && $row['poll_until'] > $now)), 0, $limit);
    }

    public function attention(int $limit, int $since): array
    {
        return array_slice(array_values(array_filter($this->rows, fn ($row) => ($row['attention'] !== null || $row['problem'] !== null) && $row['updated'] >= $since)), 0, $limit);
    }

    public function kvGet(string $key, int $maxAge = 0): ?string
    {
        return $this->kv[$key] ?? null;
    }

    public function kvSet(string $key, string $value): void
    {
        $this->kv[$key] = $value;
    }

    public function takeTurn(string $key, int $every): bool
    {
        if (isset($this->turns[$key]) && $this->turns[$key] > time() - $every) {
            return false;
        }
        $this->turns[$key] = time();

        return true;
    }

    public function claim(string $invoiceId, int $stale): bool
    {
        $row = $this->rows[strtolower($invoiceId)];
        if ($row['apply_state'] === 'applying' && $row['claimed'] !== null && $row['claimed'] >= time() - $stale) {
            return false;
        }
        $this->update($invoiceId, ['apply_state' => 'applying', 'claimed' => time()]);

        return true;
    }

    public function lock(int $fbInvoice): bool
    {
        return true;
    }

    public function unlock(int $fbInvoice): void {}
}

/** The CoinsPay API in memory: invoices by id, and the requests that came. */
final class FakeClient extends ApiClient
{
    public array $invoices = [];

    public array $created = [];

    public array $cancelled = [];

    public int $reads = 0;

    /** Invoices are made by a test key. */
    public bool $test = false;

    public array $shop = ['shop' => ['id' => 's1', 'name' => 'Northwind Hosting'], 'key' => ['test' => false, 'access' => 'invoices']];

    public function __construct() {}

    public function shop(): array
    {
        return $this->shop;
    }

    /** An error every request of the kind gets: invoice, currencies. */
    public array $errors = [];

    /** Status of the invoices "made": closed ones stand for an idempotency key met before. */
    public string $createStatus = 'open';

    /** pay_url of the invoices made, '' for the usual one. */
    public string $payUrl = '';

    public int $currencyCalls = 0;

    public function currencies(): array
    {
        $this->currencyCalls++;
        if (isset($this->errors['currencies'])) {
            throw $this->errors['currencies'];
        }

        return ['fiat' => [['code' => 'USD', 'scale' => 2], ['code' => 'EUR', 'scale' => 2], ['code' => 'JPY', 'scale' => 0]], 'crypto' => [['code' => 'USDT', 'scale' => 6], ['code' => 'ETH', 'scale' => 18]]];
    }

    public function createInvoice(array $body, string $idempotencyKey): array
    {
        $this->created[] = [$body, $idempotencyKey];
        $id = sprintf('00000000-0000-4000-8000-%012d', count($this->created));

        return $this->invoices[$id] = [
            'id' => $id, 'number' => count($this->created), 'status' => $this->createStatus, 'test' => $this->test,
            'currency' => $body['currency'], 'amount' => $body['amount'], 'amount_paid' => '0', 'amount_pending' => '0',
            'amount_due' => $body['amount'], 'amount_overpaid' => null, 'paid_late' => false, 'paid_manually' => false,
            'pay_url' => $this->payUrl !== '' ? $this->payUrl : 'https://pay.example/pay/' . $id, 'payments' => [],
        ];
    }

    public function invoice(string $id): array
    {
        $this->reads++;
        if (isset($this->errors['invoice'])) {
            throw $this->errors['invoice'];
        }
        if (!isset($this->invoices[$id])) {
            throw new ApiError(404, 'NOT_FOUND', 'Invoice not found');
        }

        return $this->invoices[$id];
    }

    public function cancelInvoice(string $id): array
    {
        $this->cancelled[] = $id;
        $this->invoices[$id]['status'] = 'cancelled';

        return $this->invoices[$id];
    }

    /** Sets fields of an invoice, as a payment or the dashboard would. */
    public function set(string $id, array $fields): array
    {
        return $this->invoices[$id] = $fields + $this->invoices[$id];
    }
}

/** A module on the fakes. */
function fake_module(FakeHost $host, MemoryRepo $repo, ?FakeClient $client = null): Module
{
    $module = new Module($host, $host->settings);
    $module->setRepo($repo);
    if ($client) {
        $module->setClient($client);
    }

    return $module;
}
