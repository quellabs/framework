{literal}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Location Monitor Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }

        .container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .status {
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
            font-weight: bold;
            text-align: center;
        }

        .status.tracking { background: #d4edda; color: #155724; }
        .status.stopped { background: #f8d7da; color: #721c24; }
        .status.error { background: #fff3cd; color: #856404; }

        .location-info {
            background: #e9ecef;
            padding: 15px;
            border-radius: 4px;
            margin: 10px 0;
            font-family: monospace;
            font-size: 14px;
        }

        .location-row {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
            padding: 3px 0;
            border-bottom: 1px solid #ccc;
        }

        .location-row:last-child {
            border-bottom: none;
        }

        .events {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }

        .event {
            padding: 8px;
            margin: 5px 0;
            background: white;
            border-radius: 3px;
            font-size: 13px;
            border-left: 4px solid #007bff;
        }

        .event.update { border-left-color: #17a2b8; }
        .event.error { border-left-color: #dc3545; }

        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 4px;
            cursor: pointer;
            margin: 5px;
            font-size: 16px;
        }

        button:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }

        button.stop {
            background: #dc3545;
        }

        .controls {
            text-align: center;
            margin: 20px 0;
        }

        .stats {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
            margin: 15px 0;
        }

        .stat {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            text-align: center;
            border: 1px solid #dee2e6;
        }

        .stat-value {
            font-size: 18px;
            font-weight: bold;
            color: #007bff;
        }

        .stat-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>📍 Location Monitor Test</h1>
    <p>Simple GPS location tracking to test coordinate accuracy and movement detection.</p>

    <div id="status" class="status stopped">
        ⏹️ Location tracking stopped
    </div>

    <div class="controls">
        <button id="startBtn" onclick="startTracking()">Start Tracking</button>
        <button id="stopBtn" onclick="stopTracking()" disabled class="stop">Stop Tracking</button>
        <button onclick="clearLog()">Clear Log</button>
    </div>

    <div class="stats">
        <div class="stat">
            <div id="updateCount" class="stat-value">0</div>
            <div class="stat-label">Updates</div>
        </div>
        <div class="stat">
            <div id="totalDistance" class="stat-value">0m</div>
            <div class="stat-label">Distance</div>
        </div>
        <div class="stat">
            <div id="avgAccuracy" class="stat-value">--</div>
            <div class="stat-label">Avg Accuracy</div>
        </div>
    </div>
</div>

<div class="container">
    <h3>📊 Current Location</h3>
    <div id="locationInfo" class="location-info">
        <div style="text-align: center; color: #6c757d;">
            Start tracking to see location data
        </div>
    </div>
</div>

<div class="container">
    <h3>📝 Location Log</h3>
    <div id="events" class="events">
        <div style="text-align: center; color: #6c757d;">
            No location updates yet
        </div>
    </div>
</div>

<script>
    let watchId = null;
    let tracking = false;
    let lastLocation = null;
    let updateCount = 0;
    let totalDistance = 0;
    let accuracySum = 0;
    let accuracyCount = 0;

    // Location tracking functions
    function startTracking() {
        if (!navigator.geolocation) {
            addEvent('error', 'Geolocation is not supported by this browser');
            return;
        }

        const options = {
            enableHighAccuracy: false,
            timeout: 10000,
            maximumAge: 30000
        };

        tracking = true;
        updateStatus('tracking', '🟢 Location tracking active');
        document.getElementById('startBtn').disabled = true;
        document.getElementById('stopBtn').disabled = false;

        watchId = navigator.geolocation.watchPosition(
            handlePosition,
            handleError,
            options
        );

        addEvent('update', 'Started location tracking...');
    }

    function stopTracking() {
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }

        tracking = false;
        updateStatus('stopped', '⏹️ Location tracking stopped');
        document.getElementById('startBtn').disabled = false;
        document.getElementById('stopBtn').disabled = true;

        addEvent('update', 'Stopped location tracking');
    }

    function handlePosition(position) {
        const coords = position.coords;
        const timestamp = new Date();

        const currentLocation = {
            latitude: coords.latitude,
            longitude: coords.longitude,
            accuracy: coords.accuracy,
            altitude: coords.altitude,
            heading: coords.heading,
            speed: coords.speed,
            timestamp: timestamp
        };

        updateCount++;

        // Calculate distance moved if we have a previous location
        let distanceMoved = 0;
        if (lastLocation) {
            distanceMoved = calculateDistance(
                lastLocation.latitude, lastLocation.longitude,
                currentLocation.latitude, currentLocation.longitude
            );
            totalDistance += distanceMoved;
        }

        // Update accuracy average
        if (coords.accuracy) {
            accuracySum += coords.accuracy;
            accuracyCount++;
        }

        // Update UI
        updateLocationDisplay(currentLocation);
        updateStats();

        // Add to log
        const message = lastLocation ?
            `Moved ${distanceMoved.toFixed(1)}m` :
            'Initial position acquired';
        addEvent('update', message, currentLocation);

        lastLocation = currentLocation;
    }

    function handleError(error) {
        let message = 'Unknown error occurred';

        switch(error.code) {
            case error.PERMISSION_DENIED:
                message = 'Location access denied by user';
                break;
            case error.POSITION_UNAVAILABLE:
                message = 'Location information unavailable';
                break;
            case error.TIMEOUT:
                message = 'Location request timed out';
                break;
        }

        updateStatus('error', '❌ ' + message);
        addEvent('error', message);
    }

    // Utility functions
    function calculateDistance(lat1, lng1, lat2, lng2) {
        const R = 6371000; // Earth's radius in meters
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLng = (lng2 - lng1) * Math.PI / 180;
        const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLng/2) * Math.sin(dLng/2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return R * c;
    }

    function updateStatus(type, message) {
        const statusEl = document.getElementById('status');
        statusEl.className = `status ${type}`;
        statusEl.textContent = message;
    }

    function updateLocationDisplay(location) {
        const infoEl = document.getElementById('locationInfo');

        const formatValue = (value, unit = '') => {
            if (value === null || value === undefined) return 'N/A';
            if (typeof value === 'number') return value.toFixed(6) + unit;
            return value;
        };

        infoEl.innerHTML = `
                <div class="location-row">
                    <strong>Latitude:</strong>
                    <span>${formatValue(location.latitude, '°')}</span>
                </div>
                <div class="location-row">
                    <strong>Longitude:</strong>
                    <span>${formatValue(location.longitude, '°')}</span>
                </div>
                <div class="location-row">
                    <strong>Accuracy:</strong>
                    <span>${formatValue(location.accuracy, 'm')}</span>
                </div>
                <div class="location-row">
                    <strong>Altitude:</strong>
                    <span>${formatValue(location.altitude, 'm')}</span>
                </div>
                <div class="location-row">
                    <strong>Speed:</strong>
                    <span>${formatValue(location.speed, 'm/s')}</span>
                </div>
                <div class="location-row">
                    <strong>Heading:</strong>
                    <span>${formatValue(location.heading, '°')}</span>
                </div>
                <div class="location-row">
                    <strong>Time:</strong>
                    <span>${location.timestamp.toLocaleTimeString()}</span>
                </div>
            `;
    }

    function updateStats() {
        document.getElementById('updateCount').textContent = updateCount;
        document.getElementById('totalDistance').textContent = totalDistance.toFixed(1) + 'm';

        if (accuracyCount > 0) {
            document.getElementById('avgAccuracy').textContent = (accuracySum / accuracyCount).toFixed(1) + 'm';
        } else {
            document.getElementById('avgAccuracy').textContent = '--';
        }
    }

    function addEvent(type, message, location = null) {
        const eventsEl = document.getElementById('events');

        // Clear placeholder text on first event
        if (updateCount === 0 && type === 'update') {
            eventsEl.innerHTML = '';
        }

        const eventEl = document.createElement('div');
        eventEl.className = `event ${type}`;

        const timestamp = new Date().toLocaleTimeString();
        let content = `<strong>${timestamp}</strong>: ${message}`;

        if (location) {
            content += `<br><small>📍 ${location.latitude.toFixed(6)}, ${location.longitude.toFixed(6)} (±${location.accuracy?.toFixed(1)}m)</small>`;
        }

        eventEl.innerHTML = content;
        eventsEl.insertBefore(eventEl, eventsEl.firstChild);

        // Keep only last 50 events
        while (eventsEl.children.length > 50) {
            eventsEl.removeChild(eventsEl.lastChild);
        }
    }

    function clearLog() {
        document.getElementById('events').innerHTML = '<div style="text-align: center; color: #6c757d;">No location updates yet</div>';
        updateCount = 0;
        totalDistance = 0;
        accuracySum = 0;
        accuracyCount = 0;
        lastLocation = null;
        updateStats();
    }

    // Handle page visibility changes (stop tracking when page is hidden)
    document.addEventListener('visibilitychange', function() {
        if (document.hidden && tracking) {
            addEvent('update', 'Page hidden - tracking may be limited');
        } else if (!document.hidden && tracking) {
            addEvent('update', 'Page visible - tracking resumed');
        }
    });
</script>
</body>
</html>
{/literal}