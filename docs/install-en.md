# CoinsPay for FOSSBilling — installation

The gateway lets your clients pay FOSSBilling invoices in cryptocurrency. The money goes to your own wallets; the payment goes to the client's balance and pays the invoice automatically.

You need:

- FOSSBilling 0.8.0 or newer with its cron running (`php cron.php` every 5 minutes, as FOSSBilling sets it up);
- a CoinsPay account with a shop and its payout addresses: [coinspay.app](https://coinspay.app).

## Installation

1. Download `coinspay-fossbilling-<version>.zip` from the releases of the repository.
2. Unpack it into the root folder of FOSSBilling, the one with `config.php`. Two folders appear: `modules/Coinspay` and `library/Payment/Adapter/Coinspay`.
3. In the admin area open Extensions, find "CoinsPay" and press "Activate". The module also writes the payment gateway into `library/Payment/Adapter/Coinspay`. If the web server cannot write there, the activation says so: copy the files of `modules/Coinspay/Gateway` into `library/Payment/Adapter/Coinspay` by hand and press "Activate" again.
4. Open System → Payment gateways → "New payment gateway" and install "Coinspay". Type the title your clients will see, for example "Crypto (CoinsPay)".

## Settings

The page of the module, Extensions → CoinsPay, shows the webhook address of your FOSSBilling and the state of the connection. Keep it open.

1. In the CoinsPay dashboard open your shop → "API keys" and create a key with "Invoices only" access. Copy it into "API key" of the gateway.

   Such a key can make and read invoices only: it cannot withdraw money, even if it leaks.
2. In the dashboard open "Webhook", paste the address from the page of the module (`https://<your FOSSBilling>/coinspay/webhook`) and save. Copy the `whsec_…` secret into "Webhook secret" of the gateway.
3. Leave "API address" empty: the gateway uses `https://coinspay.app`.
4. Turn the gateway on and save it. Open the page of the module again. It should show:
   - "The API key works: shop "…", live mode";
   - no line about a key with full access;
   - that the module listens to invoice changes and when the cron ran last;
   - the currencies of your FOSSBilling that CoinsPay does not accept, if there are any: invoices in them cannot be paid with CoinsPay.
5. Press "Send test event" on the webhook page of the dashboard and open the page of the module again: it notes the event.

The other settings:

- **Invoice lifetime, minutes**: how long the client has to pay, from 15 to 1440. 60 by default.
- **Return automatically**: after the payment the CoinsPay page takes the client back to the invoice.
- **Stablecoins only**: the payment page offers only USDT, USDC and other stablecoins.
- **Client pays the CoinsPay fee** and **Client pays the network fee**: the fees are added to what the client pays. The invoice still gets its own amount.
- **Debug log**: more lines in the log of FOSSBilling.

## Test mode

1. Create a test key in the dashboard (it starts with `cpk_test_`) and put it into "API key". The page of the module says "test mode".
2. With "Test key for admins only" set to "Yes" (the default), clients cannot pay with it. Signed in to the admin area, open a client's invoice in the client area (the invoice page has a link to it), pick CoinsPay and continue.
3. On the payment page press the test payment button: no coins move. The payment is added when CoinsPay confirms it, in a few seconds.
4. By default a test payment is only written to the log. To see the whole way, the balance, the invoice paid, the order activated, set "Add test payments" to "Yes". Do that only on a test copy of FOSSBilling: for FOSSBilling it is real money on the client's balance.

Put the live key back when you are done.

## How payments are added

FOSSBilling pays invoices from the client's balance, so a CoinsPay payment goes to the balance first and pays the invoice from there.

- A part paid on a CoinsPay invoice that expires goes to the balance. When the client pays again, CoinsPay asks only for the rest.
- An overpayment stays on the balance for the next invoices.
- If the invoice was cancelled or deleted meanwhile, the payment stays on the balance and the invoice is not paid: FOSSBilling would otherwise activate the order of a cancelled invoice.
- If the invoice now belongs to another client, or the client's balance is in another currency, nothing is added.

These cases are listed on the page of the module for 30 days with a link to the invoice. The money is in your CoinsPay shop: refund the client from the CoinsPay dashboard if you need to.

## If webhooks do not arrive

- Check that the webhook address in the dashboard is the one from the page of the module and that it is reachable from the internet over HTTPS.
- Check that the secret in the gateway is the current one: replacing the webhook in the dashboard makes a new secret.
- Payments still arrive without webhooks, up to 5 minutes later: the gateway asks CoinsPay about open invoices on every run of the FOSSBilling cron, and when the client comes back from the payment page. The page of the module shows when the cron ran last.

## Removal

Delete the gateway in Payment gateways, deactivate the module in Extensions and delete the folders `modules/Coinspay` and `library/Payment/Adapter/Coinspay`. The tables `coinspay_invoices` and `coinspay_kv` stay in the database.
