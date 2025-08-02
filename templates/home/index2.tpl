<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PAC Framework Example</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        #parent-app {
            background: #f0f8ff;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        #app {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 2px solid #ddd;
        }

        .status-low { color: green; }
        .status-medium { color: orange; }
        .status-high { color: red; font-weight: bold; }

        button {
            margin: 5px;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        button:hover {
            opacity: 0.8;
        }

        .primary { background: #007bff; color: white; }
        .secondary { background: #6c757d; color: white; }
        .success { background: #28a745; color: white; }
        .danger { background: #dc3545; color: white; }

        input[type="text"] {
            padding: 8px;
            margin: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        h2 { margin-top: 0; }
        h3 { margin-top: 0; }
    </style>
</head>
<body>
<div id="parent-app">
    <h2>🔵 Parent PAC Unit</h2>
    <p><strong>Parent Status:</strong> <span id="parent-status">{{ parentStatus }}</span></p>
    <p><strong>Child Count:</strong> {{ childCount }}</p>

    <div id="app">
        <h3>🟢 Child PAC Unit</h3>
        <p>Hello, <strong>{{ name }}</strong>!</p>
        <p>Count: <span class="status-{{ status }}">{{ count }}</span></p>
        <p>Status: <span class="status-{{ status }}" data-pac-bind="click:toggleStatus" style="cursor: pointer; text-decoration: underline;">{{ status }}</span> (click to change)</p>

        <div>
            <button class="primary" data-pac-bind="click:increment">Increment</button>
            <button class="secondary" data-pac-bind="click:reset">Reset</button>
            <button class="danger" data-pac-bind="click:sendCustomEvent">Send Alert to Parent</button>
        </div>

        <form data-pac-bind="submit:handleSubmit">
            <h4>Form Controls (Different Update Modes):</h4>
            <div>
                <label>Name (immediate update):</label><br>
                <input type="text" data-pac-bind="name" placeholder="Updates immediately" />
            </div>
            <div>
                <label>Message (delayed update - 500ms):</label><br>
                <input type="text" data-pac-bind="message" data-pac-update="delayed" data-pac-delay="500" placeholder="Updates after 500ms delay" />
            </div>
            <div>
                <label>Status Text (change event only):</label><br>
                <input type="text" data-pac-bind="statusText" data-pac-update="change" placeholder="Updates only on blur/change" />
            </div>
            <button type="submit" class="success">Submit Form</button>
        </form>

        <div style="margin-top: 20px; padding: 10px; background: #f8f9fa; border-radius: 4px;">
            <h4>Live Values:</h4>
            <p><strong>Name:</strong> {{ name }}</p>
            <p><strong>Message:</strong> {{ message }}</p>
            <p><strong>Status Text:</strong> {{ statusText }}</p>
        </div>
    </div>
</div>

<!-- Include the optimized PAC framework -->
<script src="wakapac.js"></script>

<!-- Your original example code -->
<script>
    {literal}
    // Create parent PAC unit first
    const parentPAC = wakaPAC('#parent-app', {
        parentStatus: 'Active',
        childCount: 0,

        // Handle updates from child PAC units
        onChildUpdate(eventType, data, childPAC) {
            console.log('🔵 Parent received update:', { eventType, data, fromChild: childPAC.container.id });

            // React to child events
            if (eventType === 'propertyChange' && data.property === 'count') {
                this.childCount = data.newValue;

                // Update parent status based on child count
                if (data.newValue >= 5) {
                    this.parentStatus = 'Child count is high!';
                } else {
                    this.parentStatus = 'Active';
                }

                // Update DOM manually since we don't have binding for this
                const statusElement = document.getElementById('parent-status');
                if (statusElement) {
                    statusElement.textContent = this.parentStatus;
                }
            }

            if (eventType === 'reset') {
                this.parentStatus = 'Child was reset';
                const statusElement = document.getElementById('parent-status');
                if (statusElement) {
                    statusElement.textContent = this.parentStatus;
                }
            }

            if (eventType === 'customAlert') {
                this.parentStatus = `🚨 ${data.message}`;
                const statusElement = document.getElementById('parent-status');
                if (statusElement) {
                    statusElement.textContent = this.parentStatus;
                    statusElement.style.color = 'red';
                    statusElement.style.fontWeight = 'bold';

                    // Reset styling after 3 seconds
                    setTimeout(() => {
                        statusElement.style.color = '';
                        statusElement.style.fontWeight = '';
                        this.parentStatus = 'Active';
                        statusElement.textContent = this.parentStatus;
                    }, 3000);
                }
            }
        }
    });

    // Create child PAC unit - it will automatically discover and register with parent
    const childPAC = wakaPAC('#app', {
        name: 'World',
        count: 0,
        message: 'Type here...',
        status: 'low',
        statusText: 'Change mode test',

        increment() {
            this.count++;
            // Update status based on count value
            if (this.count <= 2) {
                this.status = 'low';
            } else if (this.count <= 5) {
                this.status = 'medium';
            } else {
                this.status = 'high';
            }

            // Notify parent of significant events
            this.notifyParent('increment', { newCount: this.count });
        },

        toggleStatus() {
            // Cycle through status values when clicked
            const statusCycle = ['low', 'medium', 'high'];
            const currentIndex = statusCycle.indexOf(this.status);
            this.status = statusCycle[(currentIndex + 1) % statusCycle.length];

            // Notify parent of manual status change
            this.notifyParent('statusToggled', { newStatus: this.status });
        },

        reset() {
            this.count = 0;
            this.name = 'World';
            this.message = 'Reset!';
            this.status = 'low';

            // Notify parent of reset event
            this.notifyParent('reset', { timestamp: new Date().toISOString() });
        },

        sendCustomEvent() {
            // Example of sending a custom event to parent
            const alertMessage = `Alert from child at ${new Date().toLocaleTimeString()}!`;

            console.log('🟢 Child sending custom event to parent:', alertMessage);

            // Send custom event to parent with custom data
            this.notifyParent('customAlert', {
                message: alertMessage,
                timestamp: Date.now(),
                childId: 'app',
                priority: 'high'
            });
        },

        handleSubmit(event) {
            console.log('Form submitted:', {
                name: this.name,
                count: this.count,
                message: this.message,
                statusText: this.statusText
            });
        }
    }, {
        updateMode: 'immediate',
        delay: 300
    });

    // Test the reactive system with automatic hierarchy
    console.log('🟢 Child PAC Unit initialized:', childPAC);
    console.log('🔵 Parent PAC Unit:', parentPAC);
    console.log('📋 PAC Registry:', window.PACRegistry);
    console.log('🔗 Hierarchy check - Child parent:', childPAC.parent ? 'Found' : 'None');
    console.log('🔗 Hierarchy check - Parent children:', parentPAC.children.length);
    {/literal}
</script>
</body>
</html>