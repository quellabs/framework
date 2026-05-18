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

Connect using a `Slot` — a named wrapper around a callable that gives it stable identity:

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

## Slot Lifetime and Disconnecting

A `Slot` is a plain PHP object. Signals hold slots in a `WeakMap`, so when the last reference to a `Slot` is dropped, it is garbage collected and automatically removed from all connected signals — no explicit disconnect required.

**Inline slots** — simplest form, disconnect happens automatically when the connecting object is destroyed:

```php
class InventoryListener {
    public function __construct(UnitOfWork $unitOfWork) {
        // Slots are inlined — no properties needed.
        // When $this is destroyed, the slots are GC'd and disconnected automatically.
        $unitOfWork->signalPrePersist->connect(new Slot([$this, 'handlePrePersist']));
        $unitOfWork->signalPostPersist->connect(new Slot([$this, 'handlePostPersist']));
    }
}
```

This works correctly as long as the signal's lifetime does not exceed `$this`. If the signal outlives `$this`, store the slot as a property and disconnect explicitly.

**Stored slots** — use when you need explicit mid-lifetime disconnect, or when the signal outlives the connecting object:

```php
class InventoryListener {
    private Slot $handlePrePersist;
    private Slot $handlePostPersist;

    public function __construct(UnitOfWork $unitOfWork) {
        $this->unitOfWork = $unitOfWork;
        $this->handlePrePersist = new Slot([$this, 'handlePrePersist']);
        $this->handlePostPersist = new Slot([$this, 'handlePostPersist']);

        $unitOfWork->signalPrePersist->connect($this->handlePrePersist);
        $unitOfWork->signalPostPersist->connect($this->handlePostPersist);
    }

    public function detach(): void {
        $this->unitOfWork->signalPrePersist->disconnect($this->handlePrePersist);
        $this->unitOfWork->signalPostPersist->disconnect($this->handlePostPersist);
    }
}
```

**Shared slots** — a single `Slot` instance can be connected to multiple signals simultaneously. Each signal tracks its own priority for that slot independently:

```php
$slot = new Slot([$this, 'handleChange']);
$signalA->connect($slot, priority: 5);
$signalB->connect($slot, priority: 10);

$signalA->disconnect($slot); // still connected to $signalB
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
    private Slot $onOrderPlaced;

    public function __construct(SignalHub $hub) {
        $this->onOrderPlaced = new Slot([$this, 'onOrderPlaced']);

        $hub->getSignal(OrderController::class, 'orderPlaced')
            ->connect($this->onOrderPlaced);
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

**Priorities** — control slot execution order per connection. Priority belongs to the connection, not the slot, so the same slot can have different priorities on different signals:

```php
$signal->connect($auditSlot, priority: 100);    // runs first
$signal->connect($cleanupSlot, priority: -10);  // runs last
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
- **`Slot`** — wraps a callable with stable object identity; the key used by Signal's WeakMap
- **`SignalHub`** — registry and rendezvous point (`discoverSignals`, `unregisterSignals`, `getSignal`, `findSignals`)
- **`SignalHubLocator`** — optional static accessor for use outside DI contexts

Signals hold slots in a `WeakMap`, keyed by Slot object identity. This means:
- Callable equality problems are avoided entirely — identity is unambiguous
- Slots are garbage collected automatically when no longer referenced
- The same Slot can be connected to multiple signals without any special handling

## License

MIT