<p class="lead">Canvas includes a modular payment system built around <strong>PaymentRouter</strong>, which discovers installed payment provider packages automatically and routes payment operations to the correct provider based on the <code>paymentModule</code> field.</p>

<h2>Core Concepts</h2>
<ul>
    <li><strong>PaymentRouter</strong> — Discovers installed provider packages via composer metadata and routes <code>initiate()</code>, <code>refund()</code>, and <code>getPaymentOptions()</code> calls to the correct provider based on the <code>paymentModule</code> field.</li>
    <li><strong>PaymentProviderInterface</strong> — The contract every provider package implements.</li>
    <li><strong>Driver</strong> — A concrete provider implementation (e.g. <code>Quellabs\Payments\Mollie\Driver</code>). Registered automatically when its package is installed.</li>
    <li><strong>paymentModule</strong> — A string identifier that selects both the provider and the payment method, e.g. <code>'mollie_ideal'</code> or <code>'mollie_creditcard'</code>.</li>
</ul>

<h2>Installation</h2>
<p>Install the router and at least one provider package:</p>
<pre><code class="language-bash">composer require quellabs/canvas-payments
composer require quellabs/canvas-payments-mollie</code></pre>

<p><code>PaymentRouter</code> scans installed packages for a <code>provider</code> entry in their composer metadata and registers them automatically.</p>

<h2>Initiating a Payment</h2>
<p>Inject <code>PaymentRouter</code> via Canvas DI and call <code>initiate()</code> with a <code>PaymentRequest</code>:</p>
<pre><code class="language-php">use Quellabs\Payments\PaymentRouter;
use Quellabs\Payments\Contracts\PaymentRequest;
use Quellabs\Payments\Contracts\PaymentInitiationException;

class CheckoutService {

    public function __construct(private PaymentRouter $router) {}

    public function startPayment(): string {
        $request = new PaymentRequest(
            paymentModule: 'mollie_ideal',
            amount:        999,   // in minor units — €9.99
            currency:      'EUR',
            description:   'Order #12345',
            issuerId:      'ideal_INGBNL2A',
        );

        try {
            $result = $this->router->initiate($request);

            // Redirect the customer to the payment page
            return $result->redirectUrl;
        } catch (PaymentInitiationException $e) {
            // handle error
        }
    }
}</code></pre>

<p>All amounts are in minor units. <code>999</code> represents €9.99, <code>2500</code> represents €25.00.</p>

<h2>Handling Webhook Events</h2>
<p>When a payment status changes, the provider's webhook controller emits a <code>payment_exchange</code> signal carrying a <code>PaymentState</code> object. Listen for it using the <code>@ListenTo</code> annotation on any Canvas-managed class:</p>
<pre><code class="language-php">use Quellabs\Canvas\Annotations\ListenTo;
use Quellabs\Payments\Contracts\PaymentState;
use Quellabs\Payments\Contracts\PaymentStatus;

class OrderService {

    /**
     * @ListenTo("payment_exchange")
     */
    public function onPaymentExchange(PaymentState $state): void {
        match ($state->state) {
            PaymentStatus::Paid     => $this->markPaid($state->transactionId, $state->valueRequested),
            PaymentStatus::Canceled => $this->markCanceled($state->transactionId),
            PaymentStatus::Expired  => $this->markExpired($state->transactionId),
            PaymentStatus::Refunded => $this->handleRefund($state),
            default                 => null,
        };
    }
}</code></pre>

<p>Canvas wires the listener automatically. The <code>payment_exchange</code> signal carries only state — database handling belongs to your application.</p>

<h2>Issuing a Refund</h2>
<p>Call <code>refund()</code> with a <code>RefundRequest</code>:</p>
<pre><code class="language-php">use Quellabs\Payments\Contracts\RefundRequest;
use Quellabs\Payments\Contracts\PaymentRefundException;

try {
$result = $this->router->refund(new RefundRequest(
transactionId: 'tr_7UhSN1zuXS',
paymentModule: 'mollie_ideal',
amount:        500,   // in minor units — €5.00
currency:      'EUR',
description:   'Partial refund for order #12345',
));

    echo $result->refundId;
} catch (PaymentRefundException $e) {
// handle error
}</code></pre>

<h2>Fetching Refunds</h2>
<p>Retrieve all refunds issued for a transaction by calling <code>getRefunds()</code> on the provider directly:</p>
<pre><code class="language-php">use Quellabs\Payments\Mollie\Driver;
use Quellabs\Payments\Contracts\PaymentRefundException;

class RefundReportService {

    public function __construct(private Driver $mollie) {}

    public function getRefunds(string $transactionId): array {
        try {
            return $this->mollie->getRefunds($transactionId); // array of RefundResult
        } catch (PaymentRefundException $e) {
            // handle error
        }
    }
}</code></pre>

<h2>Payment Options</h2>
<p>Some payment methods expose issuer or bank selection (iDEAL, KBC, gift cards). Fetch available options to present to the customer before initiating payment:</p>
<pre><code class="language-php">use Quellabs\Payments\Contracts\PaymentException;

try {
$issuers = $this->router->getPaymentOptions('mollie_ideal');

    foreach ($issuers as $issuer) {
        echo $issuer['name'] . ' — ' . $issuer['id'];
    }
} catch (PaymentException $e) {
// handle error
}</code></pre>

<p>Methods without issuer selection return an empty array.</p>

<h2>Payment State</h2>
<p><code>PaymentState</code> is emitted via the <code>payment_exchange</code> signal on every webhook hit.</p>

<table>
    <thead>
    <tr>
        <th>Property</th>
        <th>Type</th>
        <th>Description</th>
    </tr>
    </thead>
    <tbody>
    <tr><td><code>provider</code></td><td><code>string</code></td><td>Provider identifier, e.g. <code>'mollie'</code></td></tr>
    <tr><td><code>transactionId</code></td><td><code>string</code></td><td>Provider-assigned transaction ID</td></tr>
    <tr><td><code>state</code></td><td><code>PaymentStatus</code></td><td>Current payment state</td></tr>
    <tr><td><code>internalState</code></td><td><code>string</code></td><td>Raw status string from the provider</td></tr>
    <tr><td><code>valueRequested</code></td><td><code>int</code></td><td>Original charged amount in minor units</td></tr>
    <tr><td><code>valueRefunded</code></td><td><code>int</code></td><td>Total amount refunded so far in minor units</td></tr>
    <tr><td><code>valueRefundable</code></td><td><code>int</code></td><td>Remaining refundable amount in minor units</td></tr>
    <tr><td><code>currency</code></td><td><code>string</code></td><td>ISO 4217 currency code</td></tr>
    <tr><td><code>metadata</code></td><td><code>array</code></td><td>Metadata passed through from the original request</td></tr>
    </tbody>
</table>

<h2>Payment Statuses</h2>
<table>
    <thead>
    <tr>
        <th>Status</th>
        <th>Description</th>
    </tr>
    </thead>
    <tbody>
    <tr><td><code>PaymentStatus::Pending</code></td><td>Payment is open or pending</td></tr>
    <tr><td><code>PaymentStatus::Paid</code></td><td>Payment completed successfully</td></tr>
    <tr><td><code>PaymentStatus::Canceled</code></td><td>Customer canceled — definitive</td></tr>
    <tr><td><code>PaymentStatus::Expired</code></td><td>Customer abandoned, or bank transfer timed out</td></tr>
    <tr><td><code>PaymentStatus::Failed</code></td><td>Payment failed and cannot be retried</td></tr>
    <tr><td><code>PaymentStatus::Refunded</code></td><td>Payment was refunded</td></tr>
    <tr><td><code>PaymentStatus::Unknown</code></td><td>Unrecognised status from the provider</td></tr>
    </tbody>
</table>

<h2>Discovering Registered Modules</h2>
<p>Retrieve all payment module identifiers currently registered across all installed providers:</p>
<pre><code class="language-php">$modules = $this->router->getRegisteredModules();
// ['mollie', 'mollie_ideal', 'mollie_creditcard', ...]</code></pre>

<h2>Adding a Provider Package</h2>
<p>Any package can register itself as a payment provider by declaring a <code>provider</code> entry in its <code>composer.json</code>:</p>
<pre><code class="language-json">"extra": {
    "discover": {
        "payments": {
            "provider": "Quellabs\\Payments\\Mollie\\Driver",
            "config": "/config/mollie.php"
        }
    }
}</code></pre>

<p>The declared class must implement <code>PaymentProviderInterface</code>. <code>PaymentRouter</code> validates this at discovery time and silently skips any class that does not. If two installed packages declare the same module identifier, a <code>RuntimeException</code> is thrown at boot.</p>