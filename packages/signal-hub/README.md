# SignalHub: Type-Safe Signal-Slot System for PHP

[![Latest Version](https://img.shields.io/packagist/v/quellabs/signal-hub.svg)](https://packagist.org/packages/quellabs/signal-hub)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/quellabs/signal-hub.svg)](https://packagist.org/packages/quellabs/signal-hub)

SignalHub is a Qt-inspired signal-slot implementation for PHP with strong type checking and flexible connection options.
It allows for loose coupling between components while maintaining type safety.

## Features

- **Type-safe signals and slots**: All connections are checked for type compatibility at runtime
- **Flexible connection patterns**: Support for both direct connections and wildcard patterns
- **Standalone signals**: Create signals independent of objects
- **Object-owned signals**: Define signals as part of your classes
- **Signal discovery**: Find signals by name patterns
- **Priority-based execution**: Control the order of slot execution
- **Meta-signals**: Built-in signals for monitoring hub activity
- **Automatic memory management**: WeakMap usage prevents memory leaks
- **Unified API**: Simple interface for working with both standalone and object signals

## Installation

```bash
composer require quellabs/signal-hub
```

## Basic Usage

### Standalone Signals

```php
use Quellabs\SignalHub\Signal;

// Create a standalone signal with a string parameter
$buttonClickedSignal = new Signal(['string'], 'button.clicked');

// Connect a handler to the signal
$buttonClickedSignal->connect(function(string $buttonId) {
    echo "Button clicked: {$buttonId}\n";
});

// Emit the signal
$buttonClickedSignal->emit('submit-button');
```

### Object-Owned Signals

```php
use Quellabs\SignalHub\HasSignals;
use Quellabs\SignalHub\SignalHub;
use Quellabs\SignalHub\Signal;

class Button {

    use HasSignals;
    
    private string $label;
    private Signal $clickedSignal;
    
    public function __construct(SignalHub $hub, string $label) {
        // Store the signal hub in this object (optional)
        $this->setSignalHub($hub);

        // Store the label        
        $this->label = $label;
        
        // Define signals for this object
        // Signals are automatically registered with the hub now
        $this->clickedSignal = $this->createSignal(['string'], 'clicked');    // Passes button label
    }
    
    public function click(): void {
        echo "Button '{$this->label}' was clicked\n";
        // Emit the signal with the button label as parameter
        $this->clickedSignal->emit($this->label);
    }
}
```

## Signal Discovery with the Hub

The SignalHub provides powerful signal discovery capabilities:

```php
// Find all signals matching a pattern
$buttonSignals = $hub->findSignals('button.*');

// Find all signals matching a pattern for a specific object
$buttonSignals = $hub->findSignals('clicked', $button);

// Connect to all found signals
foreach ($buttonSignals as $name => $signal) {
    echo "Found signal: {$name}\n";
    
    $signal->connect(function(string $id) use ($name) {
        echo "Handler for {$name} triggered with: {$id}\n";
    });
}

// Get a specific signal (standalone or object-owned)
$clickedSignal = $hub->getSignal('clicked', $button);       // Get object signal
$globalSignal = $hub->getSignal('application.started');     // Get standalone signal

// Get info about all registered signals
$allSignals = $hub->getAllSignals();

foreach ($allSignals as $signalInfo) {
    if ($signalInfo['standalone']) {
        echo "Standalone signal: {$signalInfo['name']}\n";
    } else {
        echo "Object signal: {$signalInfo['class']}::{$signalInfo['name']}\n";
    }
}
```

## Meta-Signals: Hub Event Monitoring

The SignalHub provides built-in meta-signals that allow you to monitor hub activity. These signals are automatically
created when the hub is instantiated and provide insights into signal lifecycle events.

### Available Meta-Signals

- **`hub.signal.registered`**: Emitted when any signal is registered with the hub
- **`hub.signal.unregistered`**: Emitted when any signal is unregistered from the hub

Both meta-signals pass the affected `Signal` object as their parameter.

### Using Meta-Signals

```php
use Quellabs\SignalHub\SignalHub;
use Quellabs\SignalHub\Signal;

$hub = new SignalHub();

// Monitor signal registration events
$hub->signalRegistered()->connect(function(Signal $signal) {
    $name = $signal->getName();
    $owner = $signal->getOwner();
    
    if ($owner === null) {
        echo "Standalone signal '{$name}' was registered\n";
    } else {
        $class = get_class($owner);
        echo "Object signal '{$class}::{$name}' was registered\n";
    }
});

// Monitor signal unregistration events
$hub->signalUnregistered()->connect(function(Signal $signal) {
    $name = $signal->getName();
    $owner = $signal->getOwner();
    
    if ($owner === null) {
        echo "Standalone signal '{$name}' was unregistered\n";
    } else {
        $class = get_class($owner);
        echo "Object signal '{$class}::{$name}' was unregistered\n";
    }
});

// Create signals - this will trigger the registered meta-signal
$standaloneSignal = new Signal(['string'], 'app.started');
$hub->registerSignal($standaloneSignal);

// Create an object with signals
class EventEmitter {
    use HasSignals;
    
    public function __construct(SignalHub $hub) {
        $this->setSignalHub($hub);
        // This will trigger the registered meta-signal
        $this->createSignal(['string'], 'event.occurred');
    }
}

$emitter = new EventEmitter($hub);
```

### Practical Meta-Signal Use Cases

#### 1. Debug Logging
```php
// Log all signal activity for debugging
$hub->signalRegistered()->connect(function(Signal $signal) {
    error_log("Signal registered: " . $signal->getName());
});

$hub->signalUnregistered()->connect(function(Signal $signal) {
    error_log("Signal unregistered: " . $signal->getName());
});
```

#### 2. Signal Registry Monitoring
```php
// Keep track of active signals
$activeSignals = [];

$hub->signalRegistered()->connect(function(Signal $signal) use (&$activeSignals) {
    $activeSignals[] = $signal->getName();
    echo "Total active signals: " . count($activeSignals) . "\n";
});

$hub->signalUnregistered()->connect(function(Signal $signal) use (&$activeSignals) {
    $key = array_search($signal->getName(), $activeSignals);
    if ($key !== false) {
        unset($activeSignals[$key]);
        echo "Total active signals: " . count($activeSignals) . "\n";
    }
});
```

#### 3. Dynamic Signal Discovery
```php
// Automatically connect to specific types of signals as they're registered
$hub->signalRegistered()->connect(function(Signal $signal) {
    $name = $signal->getName();
    
    // Auto-connect to all user-related signals
    if (strpos($name, 'user.') === 0) {
        $signal->connect(function(...$args) use ($name) {
            echo "User event detected: {$name}\n";
        });
    }
});
```

#### 4. Signal Validation and Governance
```php
// Enforce naming conventions
$hub->signalRegistered()->connect(function(Signal $signal) {
    $name = $signal->getName();
    
    // Warn about signals that don't follow naming conventions
    if (!preg_match('/^[a-z]+\.[a-z]+$/', $name)) {
        error_log("Warning: Signal '{$name}' doesn't follow naming convention");
    }
});
```

### Meta-Signal Characteristics

- **Automatic Creation**: Meta-signals are created automatically when the SignalHub is instantiated
- **Type Safety**: Both meta-signals have a single parameter of type `Signal`
- **Execution Order**: Meta-signals are emitted **before** the actual registration/unregistration occurs
- **No Registration Required**: Meta-signals don't need to be manually registered - they're always available
- **Memory Management**: Meta-signals follow the same memory management principles as regular signals

## Advanced Features

### Connection Priorities

Control the order in which slots are executed:

```php
$signal->connect($debugHandler, 100);  // Will be called first
$signal->connect($normalHandler, 0);   // Will be called second
```

### Type Checking

The system enforces strict type checking:

```php
// Create a signal with specific parameter types
$signal = new \Quellabs\SignalHub\Signal(['string', 'int'], 'user.login');

// This will work - types match
$signal->connect(function(string $username, int $userId) {
    echo "User {$username} logged in with ID {$userId}";
});

// This will throw an exception - missing type hint
$signal->connect(function($username, $userId) {
    // Error: Slot parameter 0 is not typed
});

// This will throw an exception - wrong type
$signal->connect(function(string $username, string $userId) {
    // Error: Type mismatch for parameter 1
});
```

### Disconnecting Handlers

```php
// Disconnect the handler
$signal->disconnect($handler);
```

## Architecture Overview

The system consists of three main components:

1. **SignalHub**: Registry for signal creation and discovery
   - `registerSignal()` - Register signals with the hub
   - `getSignal()` - Get a signal by name with optional owner
   - `findSignals()` - Find signals by pattern with optional owner
   - `signalRegistered()` - Access the meta-signal for registration events
   - `signalUnregistered()` - Access the meta-signal for unregistration events

2. **Signal**: Core signal functionality
   - `connect()` - Connect handlers (callable or object methods)
   - `emit()` - Emit the signal with parameters
   - `disconnect()` - Remove connections

3. **HasSignals trait**: Makes any class capable of having signals
   - `createSignal()` - Create signals owned by the object
   - `hasSignal()` - Checks if a specific signal exists in the object
   - `getSignal()` - Get a specific signal
   - `setSignalHub()` - Sets the SignalHub reference for automatic registration

## Differences from Qt

While inspired by Qt, there are some differences:

1. PHP doesn't support compile-time signal/slot connections, so all type checking is done at runtime
2. The pattern matching functionality is a PHP-specific extension not available in Qt
3. The SignalHub concept provides discovery capabilities beyond what Qt offers
4. Meta-signals for hub monitoring are a unique feature not present in Qt

## Examples

### Form Validation Example

```php
use Quellabs\SignalHub\HasSignals;
use Quellabs\SignalHub\SignalHub;
use Quellabs\SignalHub\Signal;

class Form {

    use HasSignals;
    
    private string $name;
    private Signal $submitted;
    private Signal $validated;
    
    public function __construct(string $name, SignalHub $hub = null) {
        $this->setSignalHub($hub);
        $this->name = $name;
        $this->submitted = $this->createSignal(['string', 'array'], 'submitted'); // form name, form data
        $this->validated = $this->createSignal(['string', 'bool'], 'validated');  // form name, is valid
    }
    
    public function submit(array $data): void {
        // Emit submitted signal
        $this->submitted->emit($this->name, $data);
        
        // Validate data
        $isValid = $this->validate($data);
        
        // Emit validation result
        $this->validated->emit($this->name, $isValid);
    }
    
    private function validate(array $data): bool {
        // Example validation
        return !empty($data);
    }
}

// Create form and connect signals
$hub = new SignalHub();
$loginForm = new Form('login', $hub);

// Connect to form submission
$loginForm->getSignal('submitted')->connect(function(string $formName, array $data) {
    echo "Form {$formName} was submitted with data: " . json_encode($data) . "\n";
});

// Connect to validation result
$loginForm->getSignal('validated')->connect(function(string $formName, bool $isValid) {
    if ($isValid) {
        echo "Form {$formName} is valid\n";
    } else {
        echo "Form {$formName} has errors\n";
    }
});

// Submit the form
$loginForm->submit(['username' => 'john', 'password' => 'secret']);
```

### Signal Analytics with Meta-Signals

```php
use Quellabs\SignalHub\SignalHub;
use Quellabs\SignalHub\Signal;

class SignalAnalytics {
    private array $signalStats = [];
    
    public function __construct(SignalHub $hub) {
        // Track signal registrations
        $hub->signalRegistered()->connect(function(Signal $signal) {
            $this->recordSignalRegistration($signal);
        });
        
        // Track signal unregistrations
        $hub->signalUnregistered()->connect(function(Signal $signal) {
            $this->recordSignalUnregistration($signal);
        });
    }
    
    private function recordSignalRegistration(Signal $signal): void {
        $name = $signal->getName();
        $owner = $signal->getOwner();
        
        if (!isset($this->signalStats[$name])) {
            $this->signalStats[$name] = [
                'registrations' => 0,
                'unregistrations' => 0,
                'is_standalone' => $owner === null,
                'owner_class' => $owner ? get_class($owner) : null
            ];
        }
        
        $this->signalStats[$name]['registrations']++;
        echo "Signal '{$name}' registered (total: {$this->signalStats[$name]['registrations']})\n";
    }
    
    private function recordSignalUnregistration(Signal $signal): void {
        $name = $signal->getName();
        
        if (isset($this->signalStats[$name])) {
            $this->signalStats[$name]['unregistrations']++;
            echo "Signal '{$name}' unregistered (total: {$this->signalStats[$name]['unregistrations']})\n";
        }
    }
    
    public function getStatistics(): array {
        return $this->signalStats;
    }
}

// Usage
$hub = new SignalHub();
$analytics = new SignalAnalytics($hub);

// Create some signals to see analytics in action
$signal1 = new Signal(['string'], 'test.signal');
$hub->registerSignal($signal1);

$signal2 = new Signal(['int'], 'another.signal');
$hub->registerSignal($signal2);

// View statistics
print_r($analytics->getStatistics());
```

## Best Practices

1. Use meaningful signal names, preferably in dot notation (e.g., 'user.login', 'form.submitted')
2. Always add type hints to signal handlers
3. Use the HasSignals trait for object-owned signals
4. Register important signals with the SignalHub for discovery
5. Use pattern matching for logging and debugging purposes
6. Keep signals focused on specific events
7. Consider using priorities for handlers that need to run first or last
8. Use the unified getSignal() and findSignals() methods for a consistent experience
9. Leverage meta-signals for debugging, monitoring, and dynamic behavior
10. Use meta-signals to enforce naming conventions and governance policies
11. Consider signal analytics and monitoring in production environments

## Memory Management

SignalHub uses PHP's WeakMap for automatic memory management. This means:

- Object-owned signals are automatically cleaned up when their owner objects are garbage collected
- No manual cleanup is required for object signals
- Memory leaks are prevented even in long-running applications
- Meta-signals follow the same memory management principles

## License

This library is licensed under the MIT License.