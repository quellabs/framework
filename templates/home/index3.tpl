{literal}
<!DOCTYPE html>
<html>
<head>
    <title>Canvas Geo-Fencing Dashboard</title>
    <script src="wakapac.js"></script>
    <link rel="stylesheet" href="geofencing.css">
</head>
<body>
<div id="geofencing-app">
    <!-- Status Panel -->
    <div class="status-panel" data-pac-bind="class:statusClass">
        <h2>Geo-Fencing Dashboard</h2>
        <div class="status-indicator" data-pac-bind="visible:tracking">
            <span class="indicator active"></span>
            Tracking Active
        </div>
        <div class="status-indicator" data-pac-bind="visible:!tracking">
            <span class="indicator inactive"></span>
            Tracking Stopped
        </div>

        <!-- Current Location -->
        <div data-pac-bind="visible:currentLocation">
            <h3>Current Location</h3>
            <p>Lat: {{currentLocation.latitude}}</p>
            <p>Lng: {{currentLocation.longitude}}</p>
            <p>Accuracy: {{currentLocation.accuracy}}m</p>
            <p>Last Update: {{lastUpdate}}</p>
        </div>
    </div>

    <!-- Controls -->
    <div class="controls">
        <button data-pac-bind="click:startTracking,enable:!tracking" class="btn-primary">
            Start Tracking
        </button>
        <button data-pac-bind="click:stopTracking,enable:tracking" class="btn-secondary">
            Stop Tracking
        </button>
        <button data-pac-bind="click:clearEvents" class="btn-outline">
            Clear Events
        </button>
    </div>

    <!-- Fence Events -->
    <div class="events-section">
        <h3>Recent Fence Events ({{events.length}})</h3>
        <div data-pac-bind="visible:events.length === 0" class="no-events">
            No fence events yet. Start tracking to see events.
        </div>

        <div data-pac-bind="foreach:events then onEventsUpdated"
             data-pac-item="event" data-pac-index="index" class="events-list">
            <div class="event-item" data-pac-bind="class:event.eventType">
                <div class="event-header">
                        <span class="event-type" data-pac-bind="class:event.eventType">
                            {{event.eventType === 'enter' ? '📍 ENTERED' : '🚪 EXITED'}}
                        </span>
                    <span class="event-time">{{event.timestamp}}</span>
                </div>
                <div class="event-details">
                    <strong>{{event.fence_name || 'Fence #' + event.fenceId}}</strong>
                    <br>
                    Location: {{event.latitude}}, {{event.longitude}}
                </div>
                <button data-pac-bind="click:removeEvent" class="btn-remove">×</button>
            </div>
        </div>
    </div>

    <!-- Active Fences -->
    <div class="fences-section">
        <h3>Active Geo-Fences ({{activeFences.length}})</h3>
        <div data-pac-bind="foreach:activeFences" data-pac-item="fence" class="fences-list">
            <div class="fence-item">
                <h4>{{fence.name}}</h4>
                <p>Type: {{fence.type}}</p>
                <p data-pac-bind="visible:fence.type === 'circle'">
                    Center: {{fence.geometry.center.lat}}, {{fence.geometry.center.lng}}
                    <br>Radius: {{fence.geometry.radius}}m
                </p>
                <p data-pac-bind="visible:fence.type === 'polygon'">
                    Vertices: {{fence.polygonCoordinates.length}}
                </p>
                <button data-pac-bind="click:toggleFence, class:fence.active ? 'btn-danger' : 'btn-success'">
                    {{fence.active ? 'Disable' : 'Enable'}}
                </button>
            </div>
        </div>
    </div>

    <!-- Error Messages -->
    <div data-pac-bind="visible:error" class="error-panel">
        <strong>Error:</strong> {{error}}
        <button data-pac-bind="click:clearError" class="btn-close">×</button>
    </div>

    <!-- Settings Panel -->
    <div class="settings-panel" data-pac-bind="visible:showSettings">
        <h3>Tracking Settings</h3>
        <label>
            Update Frequency:
            <select data-pac-bind="value:settings.updateMode">
                <option value="high">High (GPS always on)</option>
                <option value="balanced">Balanced (recommended)</option>
                <option value="power">Power Saving</option>
            </select>
        </label>
        <label>
            <input type="checkbox" data-pac-bind="checked:settings.enableNotifications">
            Enable Browser Notifications
        </label>
        <label>
            <input type="checkbox" data-pac-bind="checked:settings.showDebug">
            Show Debug Information
        </label>
    </div>

    <button data-pac-bind="click:toggleSettings" class="settings-toggle">
        {{showSettings ? 'Hide Settings' : 'Show Settings'}}
    </button>
</div>

<script src="geofencing-wakapac.js"></script>
</body>
</html>
{/literal}