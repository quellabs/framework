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

Connect using a `Slot` — a wrapper around a callable that gives it stable object identity.
Signal owns connected Slots strongly, so inline Slots are always safe. Store a Slot as a
property only if you need to call `disconnect()` explicitly later.

```php
use Quellabs\SignalHub\Slot;

$hub->discoverSignals($controller);

// Connect directly if you hold a reference...
$controller->paymentPaid->connect(new Slot(fn(Payment $p) => ...));

// ...or via the hub if you don't
$hub->getSignal(MollieController::class, 'paymentPaid')
    ->connect(new Slot(fn(Payment $p) => ...));
```

Standalone signals work without any owning object:

```php
$signal = new Signal('app.booted');
$signal->connect(new Slot(fn() => ...));
$signal->emit();
```

## Framework Integration

Call `discoverSignals()` from whatever instantiates your objects. The emitting class stays hub-unaware:

```php
$hub->discoverSignals($controller);

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
            ->connect(new Slot([$this, 'onOrderPlaced']));
    }
}
```

## Hub API

```php
$hub->discoverSignals($controller);                      // discover Signal properties on an object
$hub->unregisterSignals($controller);                    // unregister all signals for an object
$hub->getSignal(MollieController::class, 'paymentPaid'); // look up by class name
$hub->getSignal('app.booted');                           // look up standalone signal
$hub->findSignals('payment.*');                          // wildcard search
$hub->findSignals('payment.*', $controller);             // wildcard + instance
```

## Advanced Features

**Priorities** — priority belongs to the connection, not the slot, so the same Slot can have different priorities on different signals:

```php
$signal->connect($auditSlot, priority: 100);    // runs first
$signal->connect($cleanupSlot, priority: -10);  // runs last
```

**Shared slots** — a single Slot can be connected to multiple signals simultaneously:

```php
$slot = new Slot([$this, 'handleChange']);
$signalA->connect($slot, priority: 5);
$signalB->connect($slot, priority: 10);

$signalA->disconnect($slot); // still connected to $signalB
```

**Explicit disconnect** — store the Slot as a property and call `disconnect()` when needed:

```php
class InventoryListener {
    private Slot $handlePrePersist;

    public function __construct(UnitOfWork $unitOfWork) {
        $this->unitOfWork = $unitOfWork;
        $this->handlePrePersist = new Slot([$this, 'handlePrePersist']);
        $unitOfWork->signalPrePersist->connect($this->handlePrePersist);
    }

    public function detach(): void {
        $this->unitOfWork->signalPrePersist->disconnect($this->handlePrePersist);
    }
}
```

**Meta-signals** — react to hub activity:

```php
$hub->signalRegistered()->connect(new Slot(function(Signal $signal) {
    if (str_starts_with($signal->getName(), 'payment.')) {
        $signal->connect(new Slot($this->auditLogger(...)));
    }
}));
```

## Architecture

Four classes, no traits:

- **`Signal`** — holds connections, emits to slots (`connect`, `disconnect`, `emit`, `isConnected`)
- **`Slot`** — wraps a callable with stable object identity; the unit of connection
- **`SignalHub`** — registry and rendezvous point (`discoverSignals`, `unregisterSignals`, `getSignal`, `findSignals`)
- **`SignalHubLocator`** — optional static accessor for use outside DI contexts

Signal owns its Slots via a plain array keyed by `spl_object_id()`, giving connections
an unambiguous lifetime: a Slot stays connected until `disconnect()` is called or the
Signal is destroyed. Object-owned signals on the hub are stored in a `WeakMap` so they
are garbage collected automatically when the owning object goes out of scope.

## License

MIT