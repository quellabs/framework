# SignalHub: Type-Safe Signal-Slot System for PHP

[![Latest Version](https://img.shields.io/packagist/v/quellabs/signal-hub.svg)](https://packagist.org/packages/quellabs/signal-hub)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/quellabs/signal-hub.svg)](https://packagist.org/packages/quellabs/signal-hub)

SignalHub is a Qt-inspired signal-slot implementation for PHP with strong type checking and flexible connection options. It allows for loose coupling between components while maintaining type safety.

## Features

- **Type-safe signals and slots**: All connections are checked for type compatibility at runtime
- **Flexible connection patterns**: Support for both direct connections and wildcard patterns
- **Standalone signals**: Create signals independent of objects
- **Object-owned signals**: Define signals as part of your classes
- **Signal discovery**: Find signals by name patterns
- **Priority-based execution**: Control the order of slot execution
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

## Best Practices

1. Use meaningful signal names, preferably in dot notation (e.g., 'user.login', 'form.submitted')
2. Always add type hints to signal handlers
3. Use the HasSignals trait for object-owned signals
4. Register important signals with the SignalHub for discovery
5. Use pattern matching for logging and debugging purposes
6. Keep signals focused on specific events
7. Consider using priorities for handlers that need to run first or last
8. Use the unified getSignal() and findSignals() methods for a consistent experience

## License

This library is licensed under the MIT License.