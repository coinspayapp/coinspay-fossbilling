<?php

namespace Box\Mod\Coinspay\Lib;

use RuntimeException;
use Throwable;

/**
 * Applies the state of a CoinsPay invoice to its FOSSBilling invoice: lock → fresh invoice →
 * decision → funds on the client balance → the invoice paid from it.
 *
 * FOSSBilling itself does not guard a payment: no lock around its callbacks, no unique
 * transaction id, nothing against adding the same funds twice. The named lock of the invoice,
 * the claim of the row and the balance rows of the module (type "coinspay", the row id as the
 * reference) do it here: before adding anything the module reads what it added already.
 */
final class Sync
{
    /** Open invoices are polled on every run of the cron (5 minutes), closed ones every 30 minutes. */
    public const POLL_OPEN = 120;

    public const POLL_CLOSED = 1800;

    /** A claim older than this belongs to a request that died: see Repo::claim(). */
    public const CLAIM_STALE = 600;

    /** The module pays an invoice now: its own invoice hooks keep out. */
    public static bool $applying = false;

    /**
     * FOSSBilling invoices an admin is deleting now: their hook runs before the row is gone, and
     * money that comes in that moment must not pay the invoice.
     */
    public static array $deleting = [];

    /** Code of the exception when another request holds the payment: no failure to report. */
    public const BUSY = 409;

    public function __construct(private readonly Module $module) {}

    /**
     * @param  array  $invoice  CoinsPay invoice from the API.
     * @return string What happened, for the log and the webhook answer.
     *
     * @throws RuntimeException The FOSSBilling invoice is busy, or the funds did not get added;
     *                          the caller retries later.
     */
    public function apply(array $invoice): string
    {
        $repo = $this->module->repo();
        $row = $repo->find(Util::value($invoice, 'id'));
        if (!$row) {
            return 'not an invoice of this FOSSBilling';
        }
        $fbInvoice = (int) $row['fb_invoice'];
        if (!$repo->lock($fbInvoice)) {
            throw new RuntimeException('the invoice is busy');
        }
        try {
            $result = $this->applyLocked($row['invoice_id'], $invoice);
        } finally {
            $repo->unlock($fbInvoice);
        }
        if ($result[1]) {
            // The invoice got paid: other open CoinsPay invoices of it are not wanted any more.
            $this->module->payment()->cancelReplaced($fbInvoice, $row['invoice_id']);
        }

        return $result[0];
    }

    /** @return array {0: what happened, 1: whether the FOSSBilling invoice got paid by it} */
    private function applyLocked(string $invoiceId, array $invoice): array
    {
        $host = $this->module->host();
        $repo = $this->module->repo();
        $text = $this->module->adminText();
        // Fresh copies: another request may have changed both while we waited for the lock.
        $row = $repo->find($invoiceId);
        if (!$row) {
            return ['not an invoice of this FOSSBilling', false];
        }

        $status = Util::value($invoice, 'status');
        $repo->update($invoiceId, ['status' => $status, 'snapshot' => Util::json(self::snapshot($invoice))]);
        $fingerprint = Decision::fingerprint($invoice);
        // A payment interrupted halfway is never "no change": it is checked again below.
        if ($row['fingerprint'] === $fingerprint && $row['apply_state'] !== 'applying') {
            $this->settle($invoiceId, $status, $row);

            return ['no change', false];
        }

        $fbInvoice = (int) $row['fb_invoice'];
        $clientId = (int) $row['fb_client'];
        $reference = self::reference($invoiceId);
        $fb = $this->fbInvoice($fbInvoice);
        $client = $host->client($clientId);
        $decision = Decision::decide(
            $invoice,
            [
                'status' => $fb['status'] ?? 'deleted',
                'requested' => $row['due'],
                'credited' => $client ? $host->credited($clientId, $reference) : '0',
                // The invoice was deleted and its id given to an invoice of another client.
                'client_changed' => $fb !== null && (int) $fb['client_id'] !== $clientId,
                'client_missing' => $client === null,
                // The balance keeps the currency of the client: an amount in another one does not belong there.
                'currency_changed' => $client !== null && strtoupper((string) $client['currency']) !== strtoupper((string) $row['currency']),
                'test_admin' => !empty($row['test_admin']),
                'apply_test' => $this->module->settings()->flag('apply_test'),
            ],
            $text
        );
        $action = $decision['action'];
        $note = $decision['note'];
        $attention = !empty($decision['attention']);
        $paidNow = false;
        // Paying from the balance failed: the next webhook or poll must try again.
        $retry = false;

        $amount = $decision['amount'] . ' ' . Util::value($invoice, 'currency');
        if ($action === Decision::CREDIT) {
            $description = $text->t('balance_description', Util::value($invoice, 'number'), $fb['number'] ?? ('#' . $fbInvoice));
            try {
                $this->credit($row, $decision['amount'], $description);
            } catch (RuntimeException $e) {
                // Received, not on the balance: the admin sees it until a retry adds it.
                if ($e->getCode() !== self::BUSY) {
                    $repo->update($invoiceId, ['problem' => $text->t('log_not_added', Util::value($invoice, 'number'), $amount, $e->getMessage())]);
                }

                throw $e;
            }
            $this->record($row, $invoice, $decision['amount']);
        }
        if (($action === Decision::CREDIT || $action === Decision::PAY) && $decision['pay']) {
            // The invoice now, not as the decision saw it: cancelled meanwhile, it is not paid.
            $now = $this->fbInvoice($fbInvoice);
            if ($now !== null && $now['status'] === 'unpaid') {
                [$paidNow, $retry] = $this->pay($fbInvoice, $now, $row);
            } elseif ($action === Decision::CREDIT) {
                $note .= ' ' . $text->t($now === null ? 'log_invoice_gone' : 'log_not_unpaid', (string) ($now['status'] ?? 'deleted'));
                $attention = true;
            }
        }

        // Before the log: a log the web server cannot write must not lose the note for the admin.
        if ($attention) {
            $repo->update($invoiceId, ['attention' => $note]);
        }
        if ($note !== '') {
            $host->log($action === Decision::HOLD || $attention ? 'warning' : 'info', sprintf('#%d: %s', $fbInvoice, $note), self::logContext($invoice, $row));
        }

        // Only after the action went through: had it failed, the next webhook or poll must decide
        // again, not see "no change" and lose the payment.
        if ($retry) {
            $repo->update($invoiceId, [
                'problem' => $text->t('log_not_paid', Util::value($invoice, 'number'), (string) ($fb['number'] ?? $fbInvoice)),
                'next_poll' => time() + self::POLL_OPEN < (int) $row['poll_until'] ? time() + self::POLL_OPEN : null,
            ]);
        } else {
            $repo->update($invoiceId, ['fingerprint' => $fingerprint, 'problem' => null]);
            $this->settle($invoiceId, $status, $row);
        }
        $this->module->debug(sprintf('invoice #%d, CoinsPay %s (%s): %s', $fbInvoice, $invoiceId, $status, $action));

        return [$action . ($note !== '' ? ': ' . $note : ''), $paidNow];
    }

    /**
     * Reference of the balance rows of a CoinsPay invoice: its id without dashes, cut to the 20
     * characters FOSSBilling keeps. Unique to the CoinsPay invoice, so tables of the module made
     * anew never meet the rows of old payments.
     */
    public static function reference(string $invoiceId): string
    {
        return substr(str_replace('-', '', strtolower($invoiceId)), 0, 20);
    }

    /** A FOSSBilling invoice as the module must see it: one an admin is deleting is gone. */
    private function fbInvoice(int $fbInvoice): ?array
    {
        return isset(self::$deleting[$fbInvoice]) ? null : $this->module->host()->invoice($fbInvoice);
    }

    /** Transaction id of a CoinsPay invoice on the FOSSBilling invoice. */
    public static function transactionId(string $invoiceId, bool $test): string
    {
        return ($test ? 'test-' : '') . strtolower($invoiceId);
    }

    /**
     * Brings the funds of a CoinsPay invoice on the client balance up to $target, exactly once:
     * under the claim of the row, after reading what the balance holds for it already.
     *
     * @throws RuntimeException Another request adds them now, or they did not get added.
     */
    private function credit(array $row, string $target, string $description): void
    {
        $host = $this->module->host();
        $repo = $this->module->repo();
        $clientId = (int) $row['fb_client'];
        $reference = self::reference($row['invoice_id']);
        if (!$repo->claim($row['invoice_id'], self::CLAIM_STALE)) {
            throw new RuntimeException('the payment of invoice #' . $row['fb_invoice'] . ' is being added by another request', self::BUSY);
        }
        $done = false;
        try {
            // The request that held the claim before may have added them and died.
            $credited = $host->credited($clientId, $reference);
            if (Util::compare($target, $credited, Decision::SCALE) > 0) {
                $missing = Util::formatAmount((float) $target - (float) $credited, Decision::SCALE);
                $this->guarded(fn () => $host->addFunds($clientId, $missing, $description, $reference));
            }
            $done = Util::compare($host->credited($clientId, $reference), $target, Decision::SCALE) >= 0;
        } finally {
            $repo->update($row['invoice_id'], ['apply_state' => $done ? 'applied' : 'none', 'claimed' => null]);
        }
        if (!$done) {
            throw new RuntimeException('FOSSBilling did not add the payment of invoice #' . $row['fb_invoice'] . ' to the balance');
        }
    }

    /**
     * Pays the invoice from the balance. A balance that does not cover it is no failure: the
     * client pays the rest. An error is left to the next webhook or poll.
     *
     * @return array {0: whether the invoice is paid now, 1: whether paying failed}
     */
    private function pay(int $fbInvoice, array $fb, array $row): array
    {
        // An "add funds" invoice is paid by what its own CoinsPay invoices brought.
        $references = [];
        if (!empty($fb['deposit'])) {
            foreach ($this->module->repo()->forInvoice($fbInvoice) as $other) {
                if ((int) $other['fb_client'] === (int) $row['fb_client']) {
                    $references[] = self::reference($other['invoice_id']);
                }
            }
        }
        $paid = false;
        $failed = true;
        $this->guarded(function () use ($fbInvoice, $references, &$paid, &$failed) {
            $paid = $this->module->host()->payInvoice($fbInvoice, $references);
            $failed = false;
        });

        return [$paid, $failed];
    }

    /** The payment on the invoice page of the admin area: shown, never counted. */
    private function record(array $row, array $invoice, string $amount): void
    {
        try {
            $this->module->host()->recordTransaction(
                (int) $row['fb_invoice'],
                self::transactionId($row['invoice_id'], !empty($invoice['test'])),
                $amount,
                (string) $row['currency'],
                $this->module->adminText()->t('transaction_note', Util::value($invoice, 'number'))
            );
        } catch (Throwable $e) {
            $this->module->host()->log('error', 'showing the payment on invoice #' . $row['fb_invoice'] . ': ' . $e->getMessage());
        }
    }

    /** Runs a write to FOSSBilling with the invoice hooks of the module kept out. */
    private function guarded(callable $write): void
    {
        $was = self::$applying;
        self::$applying = true;
        try {
            $write();
        } catch (Throwable $e) {
            // A hook of FOSSBilling or of another extension failed after the row was saved:
            // whether it got added is checked by the caller.
            $this->module->host()->log('error', 'writing to FOSSBilling: ' . $e->getMessage());
            if (!$e instanceof \Exception) {
                throw $e;
            }
        } finally {
            self::$applying = $was;
        }
    }

    /**
     * Polling of the invoice after this state: open — on every run of the cron, paid — never,
     * expired or cancelled — every 30 minutes until the end of polling: a late payment may come.
     */
    private function settle(string $invoiceId, string $status, array $row): void
    {
        $next = match ($status) {
            'open' => time() + self::POLL_OPEN,
            'paid' => null,
            default => time() + self::POLL_CLOSED,
        };
        if ($next !== null && $next >= (int) $row['poll_until']) {
            $next = null;
        }
        $this->module->repo()->update($invoiceId, ['next_poll' => $next]);
    }

    /** What the module keeps of an invoice: for the status page and the next decision. */
    public static function snapshot(array $invoice): array
    {
        $copy = [];
        foreach (['id', 'number', 'status', 'test', 'currency', 'amount', 'amount_paid', 'amount_pending', 'amount_due', 'amount_overpaid', 'pay_url', 'expires_at', 'paid_late', 'paid_manually'] as $field) {
            $copy[$field] = $invoice[$field] ?? null;
        }

        return $copy;
    }

    private static function logContext(array $invoice, array $row): array
    {
        return [
            'coinspay_invoice' => Util::value($invoice, 'id'),
            'number' => Util::value($invoice, 'number'),
            'status' => Util::value($invoice, 'status'),
            'test' => !empty($invoice['test']),
            'amount' => Util::value($invoice, 'amount') . ' ' . Util::value($invoice, 'currency'),
            'paid' => Util::value($invoice, 'amount_paid'),
            'pending' => Util::value($invoice, 'amount_pending'),
            'requested' => (string) $row['due'],
            'sent' => (string) $row['amount'],
            'client' => (int) $row['fb_client'],
            'reference' => self::reference((string) $row['invoice_id']),
        ];
    }
}
