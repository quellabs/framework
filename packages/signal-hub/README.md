# SignalHub: Signal-Slot System for PHP

[![Latest Version](https://img.shields.io/packagist/v/quellabs/signal-hub.svg)](https://packagist.org/packages/quellabs/signal-hub)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/quellabs/signal-hub.svg)](https://packagist.org/packages/quellabs/signal-hub)

A Qt-inspired signal-slot implementation for PHP. Loose coupling between components through automatic signal discovery, with PHP's type system handling slot type safety.

## Installation

```bash
composer require quellabs/signal-hub
```

## Basic Usage

Declare signals as typed properties — your class needs no knowledge of the hub:

```php
use Quellabs\SignalHub\Signal;

class MollieController {

    public Signal $paymentPaid;
    public Signal $paymentFailed;

    public function handleWebhook(array $data): void {
        $payment = $this->fetchPayment($data['id']);
        $payment->isPaid() ? $this->paymentPaid->emit($payment) : $this->paymentFailed->emit($payment);
    }
}
```

Connect directly if you hold a reference, or via the hub if you don't.
The hub needs to know about the object first — call `registerSignals()` when instantiating it:

```php
$hub->registerSignals($controller);

// Then connect directly...
$controller->paymentPaid->connect(fn(Payment $p) => ...);

// ...or via the hub if you don't hold a reference
$hub->getSignal(MollieController::class, 'paymentPaid')->connect(fn(Payment $p) => ...);
```

Standalone signals work without any owning object:

```php
$signal = new Signal('app.booted');
$signal->connect(fn() => ...);
$signal->emit();
```

## Framework Integration

Call `registerSignals()` from whatever instantiates your objects. The emitting class stays hub-unaware:

```php
$hub->registerSignals($controller);

try {
    $controller->handle($request);
} finally {
    $hub->unregisterSignals($controller);
}
```

Consumers connect in their constructor — no controller reference needed:

```php
class InventoryService {
    public function __construct(SignalHub $hub) {
        $hub->getSignal(OrderController::class, 'orderPlaced')
            ->connect($this->onOrderPlaced(...));
    }
}
```

## Hub API

```php
$hub->getSignal(MollieController::class, 'paymentPaid'); // by class name
$hub->getSignal('app.booted');                           // standalone signal
$hub->findSignals('payment.*');                          // wildcard search
$hub->findSignals('payment.*', $controller);             // wildcard + instance
```

## Advanced Features

**Priorities** — control slot execution order:
```php
$signal->connect($auditHandler, 100);   // runs first
$signal->connect($cleanupHandler, -10); // runs last
```

**Meta-signals** — react to hub activity:
```php
$hub->signalRegistered()->connect(function(Signal $signal) {
    if (str_starts_with($signal->getName(), 'payment.')) {
        $signal->connect($this->auditLogger(...));
    }
});
```

## Architecture

Three classes, no traits:

- **`Signal`** — holds connections, emits to slots (`connect`, `disconnect`, `emit`)
- **`SignalHub`** — registry and rendezvous point (`registerSignals`, `unregisterSignals`, `getSignal`, `findSignals`)
- **`SignalHubLocator`** — optional static accessor for use outside DI contexts

Object-owned signals are stored in a `WeakMap`, so they're garbage collected when the owning object goes out of scope.

## License

MIT