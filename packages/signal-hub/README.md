# SignalHub: Signal-Slot System for PHP

[![Latest Version](https://img.shields.io/packagist/v/quellabs/signal-hub.svg)](https://packagist.org/packages/quellabs/signal-hub)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/quellabs/signal-hub.svg)](https://packagist.org/packages/quellabs/signal-hub)

SignalHub is a Qt-inspired signal-slot implementation for PHP. It provides loose coupling between components through
automatic signal discovery, with PHP's own type system handling type safety on slot methods.

## Features

- **Flexible signal registration**: Register signals manually, or use `registerSignals()` to discover all `Signal` properties on an object via reflection
- **Zero coupling in emitters**: Classes that emit signals have no dependency on the hub
- **Standalone signals**: Create signals independent of any object
- **Object-owned signals**: Declare signals as typed properties on your classes
- **Signal discovery**: Find signals by name or wildcard pattern
- **Class-based lookup**: Find signals by class name when you don't hold a reference to the object
- **Priority-based execution**: Control the order of slot execution
- **Meta-signals**: Built-in signals for monitoring hub activity
- **Automatic memory management**: WeakMap usage prevents memory leaks

## Installation

```bash
composer require quellabs/signal-hub
```

## Basic Usage

### Standalone Signals

```php
use Quellabs\SignalHub\Signal;

$signal = new Signal('payment.paid');

$signal->connect(function(Payment $payment) {
    echo "Payment received: {$payment->getId()}\n";
});

$signal->emit($payment);
```

### Object-Owned Signals

Declare signals as typed `Signal` properties on your class. The hub discovers them automatically via reflection —
your class needs no knowledge of the hub:

```php
use Quellabs\SignalHub\Signal;

class MollieController {

    public Signal $paymentPaid;
    public Signal $paymentFailed;

    public function handleWebhook(array $data): void {
        $payment = $this->fetchPayment($data['id']);

        if ($payment->isPaid()) {
            $this->paymentPaid->emit($payment);
        } else {
            $this->paymentFailed->emit($payment);
        }
    }
}
```

### Connecting to Signals

If you hold a direct reference to the object, connect directly:

```php
$controller->paymentPaid->connect(function(Payment $payment) {
    // handle payment
});
```

If the object is buried in infrastructure (auto-discovered controllers, vendor classes), use the hub:

```php
$hub->getSignal(MollieController::class, 'paymentPaid')
    ->connect(function(Payment $payment) {
        // handle payment
    });
```

## Signal Discovery with the Hub

The hub is the rendezvous point between emitters you can't directly reach and listeners that need to connect to them.
It is populated automatically by whatever instantiates controllers — in Canvas, this is the `RequestHandler`.

```php
// Get a signal by class name — no object reference needed
$signal = $hub->getSignal(MollieController::class, 'paymentPaid');

// Get a standalone signal
$signal = $hub->getSignal('application.started');

// Find all signals matching a wildcard pattern
$signals = $hub->findSignals('payment.*');

// Find all signals matching a pattern for a specific object instance
$signals = $hub->findSignals('payment.*', $controller);
```

## Framework Integration

`registerSignals()` is designed to be called by whatever instantiates your objects — a framework dispatcher,
a DI container, or a bootstrap file. The emitting class itself needs no knowledge of the hub.

A typical dispatcher integration looks like this:

```php
// After instantiating a controller or service
$hub->registerSignals($controller);

try {
    $controller->handle($request);
} finally {
    // Clean up after the request
    $hub->unregisterSignals($controller);
}
```

The emitting class just declares its signals as properties:

```php
class OrderController {

    public Signal $orderPlaced;
    public Signal $orderCancelled;

    public function placeOrder(array $data): void {
        $order = $this->createOrder($data);
        $this->orderPlaced->emit($order);
    }
}
```

And consumers connect via the hub without needing a reference to the controller instance:

```php
class InventoryService {

    public function __construct(SignalHub $hub) {
        $hub->getSignal(OrderController::class, 'orderPlaced')
            ->connect($this->onOrderPlaced(...));
    }

    public function onOrderPlaced(Order $order): void {
        $this->reserveStock($order);
    }
}
```

## Meta-Signals

The hub emits meta-signals when signals are registered or unregistered, useful for debugging and monitoring:

```php
$hub->signalRegistered()->connect(function(Signal $signal) {
    $owner = $signal->getOwner();
    $label = $owner ? get_class($owner) . '::' . $signal->getName() : $signal->getName();
    error_log("Signal registered: {$label}");
});

$hub->signalUnregistered()->connect(function(Signal $signal) {
    error_log("Signal unregistered: {$signal->getName()}");
});
```

### Practical uses for meta-signals

**Dynamic auto-connect** — connect to signals as they come online:

```php
$hub->signalRegistered()->connect(function(Signal $signal) {
    if (str_starts_with($signal->getName(), 'payment.')) {
        $signal->connect($this->auditLogger(...));
    }
});
```

**Naming convention enforcement**:

```php
$hub->signalRegistered()->connect(function(Signal $signal) {
    if (!preg_match('/^[a-z][a-zA-Z]*$/', $signal->getName())) {
        error_log("Warning: signal '{$signal->getName()}' doesn't follow naming convention");
    }
});
```

## Advanced Features

### Connection Priorities

```php
$signal->connect($auditHandler, 100);   // Runs first
$signal->connect($normalHandler, 0);    // Runs second
$signal->connect($cleanupHandler, -10); // Runs last
```

### Disconnecting Handlers

```php
$signal->disconnect($handler);
```

### Standalone Signal Registration

For signals not tied to any object, register them explicitly with the hub:

```php
$signal = new Signal('app.booted');
$hub->registerSignal($signal);

// Later
$hub->getSignal('app.booted')->connect($handler);
```

## Architecture

Three classes, no traits required:

- **`Signal`** — core signal object. Holds connections, emits to slots.
   - `connect(callable, priority)` — attach a slot
   - `disconnect(callable)` — detach a slot
   - `emit(...$args)` — call all connected slots

- **`SignalHub`** — registry and rendezvous point.
   - `registerSignals(object)` — discover and register all `Signal` properties on an object
   - `unregisterSignals(object)` — remove all signals for an object
   - `registerSignal(Signal)` — register a standalone signal
   - `getSignal(name, owner?)` — get signal by name, with optional object or class name owner
   - `findSignals(pattern, owner?)` — wildcard pattern search
   - `signalRegistered()` / `signalUnregistered()` — meta-signals

- **`SignalHubLocator`** — optional static accessor for the shared hub instance, useful outside DI contexts.

## Memory Management

Object-owned signals are stored in a `WeakMap` keyed by object reference. When a controller goes out of scope,
its signals are garbage collected automatically. In Canvas, signals are also explicitly unregistered after each
request via `unregisterSignals()`.

## Differences from Qt

1. Signals are regular PHP properties, not compile-time declarations
2. Type safety on slots is PHP's own — just use type hints on your slot methods
3. The `SignalHub` adds discovery capabilities beyond what Qt offers
4. Signal lifecycle is tied to the request in web contexts

## License

This library is licensed under the MIT License.