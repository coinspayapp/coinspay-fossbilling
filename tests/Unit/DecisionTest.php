<?php

use Box\Mod\Coinspay\Lib\Decision;
use Box\Mod\Coinspay\Lib\Text;
use PHPUnit\Framework\TestCase;

/**
 * CoinsPay invoice state → what happens on the client balance and the FOSSBilling invoice.
 */
final class DecisionTest extends TestCase
{
    private function invoice(array $overrides = []): array
    {
        return $overrides + [
            'id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'number' => 12, 'status' => 'open', 'test' => false,
            'currency' => 'USD', 'amount' => '100', 'amount_paid' => '0', 'amount_pending' => '0', 'amount_due' => '100',
            'amount_overpaid' => null, 'paid_late' => false, 'paid_manually' => false, 'payments' => [],
        ];
    }

    private function paid(array $overrides = []): array
    {
        return $this->invoice($overrides + ['status' => 'paid', 'amount_paid' => '100', 'amount_due' => '0',
            'payments' => [['deposit_id' => 'd1', 'status' => 'confirmed', 'chain' => 'tron', 'asset' => 'USDT', 'amount' => '100', 'tx_hash' => 'abcdef1234567890abcdef']]]);
    }

    private function decide(array $invoice, array $state = []): array
    {
        return Decision::decide($invoice, $state + ['status' => 'unpaid', 'requested' => '100.00', 'credited' => '0', 'client_changed' => false, 'client_missing' => false, 'currency_changed' => false, 'test_admin' => false, 'apply_test' => false], new Text('en'));
    }

    public function test_a_paid_invoice_goes_to_the_balance_and_pays_the_invoice()
    {
        $decision = $this->decide($this->paid());
        $this->assertSame(Decision::CREDIT, $decision['action']);
        $this->assertSame('100', $decision['amount']);
        $this->assertTrue($decision['pay']);
        $this->assertFalse($decision['attention']);
        $this->assertStringContainsString('100 USD', $decision['note']);
        $this->assertStringContainsString('tron', $decision['note']);
    }

    public function test_late_and_manual_payments_are_noted()
    {
        $decision = $this->decide($this->paid(['paid_late' => true, 'paid_manually' => true]));
        $this->assertSame(Decision::CREDIT, $decision['action']);
        $this->assertStringContainsString('after the CoinsPay invoice expired', $decision['note']);
        $this->assertStringContainsString('manually', $decision['note']);
    }

    public function test_an_overpayment_goes_to_the_balance_whole_and_the_admin_is_told()
    {
        $decision = $this->decide($this->paid(['amount_paid' => '105', 'amount_overpaid' => '5']));
        $this->assertSame(Decision::CREDIT, $decision['action']);
        $this->assertSame('105', $decision['amount'], 'the excess stays on the balance for the next invoices');
        $this->assertTrue($decision['pay']);
        $this->assertTrue($decision['attention']);
        $this->assertStringContainsString('Overpaid by 5 USD', $decision['note']);
    }

    public function test_an_invoice_paid_in_the_dashboard_brings_at_least_what_it_was_made_for()
    {
        $decision = $this->decide($this->paid(['amount_paid' => '0', 'paid_manually' => true]));
        $this->assertSame(Decision::CREDIT, $decision['action']);
        $this->assertSame('100', $decision['amount']);
    }

    public function test_what_is_on_the_balance_already_is_not_added_again()
    {
        // The invoice still unpaid: paying it from the balance is tried again, nothing is added.
        $decision = $this->decide($this->paid(), ['credited' => '100.00']);
        $this->assertSame(Decision::PAY, $decision['action']);
        $this->assertSame('', $decision['amount']);
        // Paid meanwhile: nothing to do.
        $this->assertSame(Decision::NONE, $this->decide($this->paid(), ['credited' => '100.00', 'status' => 'paid'])['action']);
    }

    public function test_money_for_an_invoice_that_no_longer_waits_stays_on_the_balance()
    {
        foreach (['canceled', 'refunded', 'paid', 'deleted'] as $status) {
            $decision = $this->decide($this->paid(), ['status' => $status]);
            $this->assertSame(Decision::CREDIT, $decision['action'], $status);
            $this->assertSame('100', $decision['amount'], $status);
            $this->assertFalse($decision['pay'], $status . ': the invoice is not paid from it');
            $this->assertTrue($decision['attention'], $status);
            $this->assertStringContainsString('stays on the client balance', $decision['note'], $status);
        }
    }

    public function test_another_client_or_a_deleted_client_goes_to_the_admin()
    {
        $decision = $this->decide($this->paid(), ['client_changed' => true]);
        $this->assertSame(Decision::HOLD, $decision['action']);
        $this->assertTrue($decision['attention']);
        $this->assertStringContainsString('another client', $decision['note']);

        $decision = $this->decide($this->paid(), ['client_missing' => true]);
        $this->assertSame(Decision::HOLD, $decision['action']);
        $this->assertStringContainsString('client is deleted', $decision['note']);
    }

    public function test_a_balance_in_another_currency_goes_to_the_admin()
    {
        $decision = $this->decide($this->paid(), ['currency_changed' => true]);
        $this->assertSame(Decision::HOLD, $decision['action']);
        $this->assertTrue($decision['attention']);
    }

    public function test_a_test_payment_counts_only_for_an_admin_and_when_allowed()
    {
        $test = $this->paid(['test' => true]);
        $this->assertSame(Decision::HOLD, $this->decide($test)['action'], 'not started by an admin');
        $this->assertSame(Decision::LOG, $this->decide($test, ['test_admin' => true])['action'], 'not allowed');
        $this->assertSame(Decision::CREDIT, $this->decide($test, ['test_admin' => true, 'apply_test' => true])['action']);
    }

    public function test_money_on_an_open_invoice_waits()
    {
        $partial = $this->decide($this->invoice(['amount_paid' => '40', 'amount_due' => '60']));
        $this->assertSame(Decision::LOG, $partial['action'], 'the client may still pay the rest on the same page');
        $this->assertStringContainsString('40 USD received', $partial['note']);

        $pending = $this->decide($this->invoice(['amount_pending' => '100', 'payments' => [['deposit_id' => 'd1', 'status' => 'pending', 'chain' => 'tron', 'asset' => 'USDT', 'amount' => '100', 'tx_hash' => 'abc']]]));
        $this->assertSame(Decision::LOG, $pending['action']);
        $this->assertStringContainsString('on its way', $pending['note']);
    }

    public function test_an_expired_or_cancelled_invoice_brings_the_part_received()
    {
        foreach (['expired', 'cancelled'] as $status) {
            $decision = $this->decide($this->invoice(['status' => $status, 'amount_paid' => '40', 'amount_due' => '60']));
            $this->assertSame(Decision::CREDIT, $decision['action'], $status);
            $this->assertSame('40', $decision['amount'], $status);
            $this->assertTrue($decision['pay'], $status . ': paid when the balance covers it');
            $this->assertStringContainsString('the client pays the rest', $decision['note'], $status);
        }
    }

    public function test_the_part_received_is_counted_without_the_fees_the_client_paid_on_top()
    {
        // 100 asked of the invoice, 103 of the client with the fees: half of the money is half of 100.
        $decision = $this->decide($this->invoice(['status' => 'expired', 'amount' => '103', 'amount_paid' => '51.5', 'amount_due' => '51.5']));
        $this->assertSame('50', $decision['amount']);
        // All of it paid: what the invoice was made for, not the fees.
        $decision = $this->decide($this->paid(['amount' => '103', 'amount_paid' => '103']));
        $this->assertSame('100', $decision['amount']);
    }

    public function test_a_late_payment_raises_the_part_added_before()
    {
        $decision = $this->decide($this->paid(['paid_late' => true]), ['credited' => '40.00']);
        $this->assertSame(Decision::CREDIT, $decision['action']);
        $this->assertSame('100', $decision['amount'], 'the total for the CoinsPay invoice: the module adds the 60 missing');
    }

    public function test_a_closed_invoice_without_money_or_with_money_on_its_way()
    {
        $this->assertSame(Decision::LOG, $this->decide($this->invoice(['status' => 'expired']))['action']);
        $this->assertStringContainsString('expired unpaid', $this->decide($this->invoice(['status' => 'expired']))['note']);
        $pending = $this->decide($this->invoice(['status' => 'expired', 'amount_pending' => '100']));
        $this->assertSame(Decision::LOG, $pending['action']);
        $this->assertStringContainsString('still on its way', $pending['note']);
    }

    public function test_a_closed_part_already_added_tries_to_pay_again_only_while_unpaid()
    {
        $closed = $this->invoice(['status' => 'expired', 'amount_paid' => '40']);
        $this->assertSame(Decision::PAY, $this->decide($closed, ['credited' => '40.00'])['action']);
        $this->assertSame(Decision::NONE, $this->decide($closed, ['credited' => '40.00', 'status' => 'canceled'])['action']);
    }

    public function test_a_currency_without_cents_brings_what_the_invoice_wanted()
    {
        // 1078.32 JPY of the invoice, CoinsPay asks the whole 1078.
        $decision = $this->decide($this->paid(['currency' => 'JPY', 'amount' => '1078', 'amount_paid' => '1078']), ['requested' => '1078.32']);
        $this->assertSame('1078.32', $decision['amount']);
    }

    public function test_the_fingerprint_follows_the_state_that_matters()
    {
        $a = Decision::fingerprint($this->paid());
        $this->assertSame($a, Decision::fingerprint($this->paid(['pay_url' => 'https://other'])));
        $this->assertNotSame($a, Decision::fingerprint($this->paid(['amount_paid' => '99'])));
        $this->assertNotSame($a, Decision::fingerprint($this->paid(['paid_late' => true])));
    }
}
