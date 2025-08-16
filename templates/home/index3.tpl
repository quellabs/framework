{literal}
<!DOCTYPE html>
<html>
<head>
    <title>Canvas Geo-Fencing Dashboard with Map</title>
    <script src="wakapac.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .dashboard {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            height: calc(100vh - 40px);
        }

        .left-panel {
            display: flex;
            flex-direction: column;
            gap: 20px;
            overflow-y: auto;
        }

        .right-panel {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .panel {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .map-container {
            flex: 1;
            min-height: 500px;
            position: relative;
            border-radius: 12px;
            overflow: hidden;
        }

        #map {
            width: 100%;
            height: 100%;
            border-radius: 12px;
        }

        .status-panel h2 {
            color: #2d3748;
            margin-bottom: 20px;
            font-size: 24px;
            font-weight: 700;
        }

        .status-indicator {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
        }

        .tracking-active .status-indicator {
            background: rgba(72, 187, 120, 0.1);
            color: #2f855a;
        }

        .tracking-inactive .status-indicator {
            background: rgba(245, 101, 101, 0.1);
            color: #c53030;
        }

        .indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        .indicator.active {
            background: #48bb78;
        }

        .indicator.inactive {
            background: #f56565;
        }

        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }

        .location-info {
            background: rgba(66, 153, 225, 0.05);
            border-radius: 8px;
            padding: 16px;
            margin-top: 16px;
        }

        .location-info h3 {
            color: #2b6cb0;
            margin-bottom: 12px;
            font-size: 16px;
        }

        .location-info p {
            margin: 6px 0;
            color: #4a5568;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }

        .controls {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-primary {
            background: #4299e1;
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background: #3182ce;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #ed8936;
            color: white;
        }

        .btn-secondary:hover:not(:disabled) {
            background: #dd6b20;
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            color: #4a5568;
            border: 2px solid #e2e8f0;
        }

        .btn-outline:hover:not(:disabled) {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        .events-section {
            flex: 1;
            min-height: 200px;
        }

        .events-section h3 {
            color: #2d3748;
            margin-bottom: 16px;
            font-size: 18px;
        }

        .no-events {
            text-align: center;
            color: #718096;
            padding: 40px 20px;
            font-style: italic;
        }

        .events-list {
            max-height: 300px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .event-item {
            background: white;
            border-radius: 8px;
            padding: 16px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border-left: 4px solid #e2e8f0;
            position: relative;
            transition: transform 0.2s;
        }

        .event-item:hover {
            transform: translateX(4px);
        }

        .event-item.enter {
            border-left-color: #48bb78;
        }

        .event-item.exit {
            border-left-color: #f56565;
        }

        .event-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .event-type {
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .event-type.enter {
            color: #2f855a;
        }

        .event-type.exit {
            color: #c53030;
        }

        .event-time {
            font-size: 12px;
            color: #718096;
        }

        .event-details {
            color: #4a5568;
            font-size: 14px;
            line-height: 1.4;
        }

        .btn-remove {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #f56565;
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
        }

        .btn-remove:hover {
            background: #e53e3e;
        }

        .error-panel {
            background: rgba(245, 101, 101, 0.1);
            border: 1px solid rgba(245, 101, 101, 0.3);
            border-radius: 8px;
            padding: 16px;
            color: #c53030;
            position: relative;
        }

        .btn-close {
            position: absolute;
            top: 8px;
            right: 8px;
            background: none;
            border: none;
            color: #c53030;
            cursor: pointer;
            font-size: 18px;
        }

        .settings-panel {
            background: rgba(102, 126, 234, 0.05);
            border-radius: 8px;
            padding: 20px;
        }

        .settings-panel h3 {
            color: #553c9a;
            margin-bottom: 16px;
        }

        .settings-panel label {
            display: block;
            margin-bottom: 12px;
            color: #4a5568;
            font-weight: 500;
        }

        .settings-panel select,
        .settings-panel input[type="checkbox"] {
            margin-left: 8px;
        }

        .settings-toggle {
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            cursor: pointer;
            font-weight: 600;
            margin-top: 16px;
        }

        .settings-toggle:hover {
            background: #5a67d8;
        }

        .fences-section h3 {
            color: #2d3748;
            margin-bottom: 16px;
            font-size: 18px;
        }

        .fence-item {
            background: white;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .fence-item h4 {
            color: #2d3748;
            margin-bottom: 8px;
        }

        .fence-item p {
            color: #718096;
            font-size: 14px;
            margin: 4px 0;
        }

        .fence-toggle-btn {
            background: #48bb78;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 8px 16px;
            cursor: pointer;
            margin-top: 8px;
            transition: background-color 0.2s;
        }

        .fence-toggle-btn.active {
            background: #f56565;
        }

        .fence-toggle-btn:hover {
            opacity: 0.9;
        }

        .btn-success {
            background: #48bb78;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 8px 16px;
            cursor: pointer;
            margin-top: 8px;
        }

        .btn-danger {
            background: #f56565;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 8px 16px;
            cursor: pointer;
            margin-top: 8px;
        }

        @media (max-width: 768px) {
            .dashboard {
                grid-template-columns: 1fr;
                height: auto;
            }

            .left-panel {
                order: 2;
            }

            .right-panel {
                order: 1;
            }

            .map-container {
                min-height: 400px;
            }
        }

        /* Custom marker styles */
        .leaflet-div-icon {
            background: transparent;
            border: none;
        }

        .position-marker {
            width: 20px;
            height: 20px;
            background: #ff4444;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            animation: locationPulse 2s infinite;
        }

        @keyframes locationPulse {
            0% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.3);
                opacity: 0.7;
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        .fence-circle {
            fill: rgba(66, 153, 225, 0.2);
            stroke: #4299e1;
            stroke-width: 2;
        }

        .fence-polygon {
            fill: rgba(237, 137, 54, 0.2);
            stroke: #ed8936;
            stroke-width: 2;
        }
    </style>
</head>
<body>
<div id="geofencing-app" class="dashboard">
    <!-- Left Panel - Controls and Events -->
    <div class="left-panel">
        <!-- Status Panel -->
        <div class="panel status-panel" data-pac-bind="class:statusClass">
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
            <div data-pac-bind="visible:currentLocation" class="location-info">
                <h3>Current Location</h3>
                <p>Lat: {{currentLocation.latitude}}</p>
                <p>Lng: {{currentLocation.longitude}}</p>
                <p>Accuracy: {{currentLocation.accuracy}}m</p>
                <p>Last Update: {{lastUpdate}}</p>
            </div>

            <!-- Controls -->
            <div class="controls">
                <button data-pac-bind="click:startTracking,enable:!tracking" class="btn btn-primary">
                    Start Tracking
                </button>
                <button data-pac-bind="click:stopTracking,enable:tracking" class="btn btn-secondary">
                    Stop Tracking
                </button>
                <button data-pac-bind="click:clearEvents" class="btn btn-outline">
                    Clear Events
                </button>
            </div>
        </div>

        <!-- Fence Events -->
        <div class="panel events-section">
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

        <!-- Settings Panel -->
        <div data-pac-bind="visible:showSettings" class="panel settings-panel">
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

        <button data-pac-bind="click:toggleSettings" class="btn settings-toggle">
            {{showSettings ? 'Hide Settings' : 'Show Settings'}}
        </button>
    </div>

    <!-- Right Panel - Map and Fences -->
    <div class="right-panel">
        <!-- Map Container -->
        <div class="panel map-container">
            <div id="map"></div>
        </div>

        <!-- Active Fences -->
        <div class="panel fences-section">
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
                    <button data-pac-bind="click:toggleFence,class:fence.active" class="fence-toggle-btn">
                        {{fence.active ? 'Disable' : 'Enable'}}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Messages -->
    <div data-pac-bind="visible:error" class="panel error-panel">
        <strong>Error:</strong> {{error}}
        <button data-pac-bind="click:clearError" class="btn-close">×</button>
    </div>
</div>

<!-- Load Leaflet JavaScript -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<!-- Enhanced Geofencing WakaPAC App -->
<script>
    const geofencingApp = wakaPAC('#geofencing-app', {
        // State
        tracking: false,
        currentLocation: null,
        events: [],
        activeFences: [],
        error: null,
        showSettings: false,
        watchId: null,

        // Non-reactive map instances (won't trigger DOM updates)
        _map: null,
        _positionMarker: null,
        _fenceMarkers: [],

        // Settings
        settings: {
            updateMode: 'balanced',
            enableNotifications: true,
            apiBase: '/api',
            showDebug: false
        },

        // Computed Properties
        computed: {
            statusClass() {
                return this.tracking ? 'tracking-active' : 'tracking-inactive';
            },

            lastUpdate() {
                if (!this.currentLocation) {
                    return 'Never';
                }
                return new Date(this.currentLocation.timestamp).toLocaleTimeString();
            }
        },

        // Methods
        async startTracking() {
            console.log('Start tracking clicked');

            if (!navigator.geolocation) {
                this.error = 'Geolocation not supported by this browser';
                return;
            }

            try {
                this.tracking = true;
                this.error = null;
                console.log('Tracking set to true');

                // Initialize map if not already done (store outside reactive system)
                if (!this._mapInstance) {
                    console.log('Initializing map...');
                    this.initializeMap();
                }

                // Try to get initial position first
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        console.log('Got initial position:', position);
                        this.handlePosition(position);
                    },
                    (error) => {
                        console.warn('Initial position failed, continuing with watch:', error);
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
                );

                // Start watching position
                console.log('Starting position watch...');
                this.watchId = navigator.geolocation.watchPosition(
                    (position) => this.handlePosition(position),
                    (error) => this.handleLocationError(error),
                    this.getLocationOptions()
                );

                // Load active fences and display them on map
                await this.loadActiveFences();
                this.displayFencesOnMap();

            } catch (error) {
                console.error('Error in startTracking:', error);
                this.error = error.message;
                this.tracking = false;
            }
        },

        stopTracking() {
            this.tracking = false;

            if (this.watchId) {
                navigator.geolocation.clearWatch(this.watchId);
                this.watchId = null;
            }
        },

        initializeMap() {
            console.log('Creating map...');

            // Wait a bit for the DOM to be ready
            setTimeout(() => {
                try {
                    // Check if Leaflet is loaded
                    if (typeof L === 'undefined') {
                        console.error('Leaflet library not loaded');
                        this.error = 'Map library not loaded';
                        return;
                    }

                    // Check if map container exists and has dimensions
                    const mapContainer = document.getElementById('map');
                    if (!mapContainer) {
                        console.error('Map container not found');
                        this.error = 'Map container not found';
                        return;
                    }

                    const rect = mapContainer.getBoundingClientRect();
                    console.log('Map container dimensions:', rect);

                    // Initialize Leaflet map
                    this.map = L.map('map').setView([40.7128, -74.0060], 13);
                    console.log('Map created:', this.map);

                    // Add OpenStreetMap tiles
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap contributors'
                    }).addTo(this.map);

                    // Create custom marker for current position
                    const positionIcon = L.divIcon({
                        className: 'leaflet-div-icon',
                        html: '<div class="position-marker"></div>',
                        iconSize: [20, 20],
                        iconAnchor: [10, 10]
                    });

                    this.positionMarker = L.marker([40.7128, -74.0060], { icon: positionIcon })
                        .addTo(this.map)
                        .bindPopup('Current Location');

                    console.log('Map initialization complete', {
                        map: !!this.map,
                        marker: !!this.positionMarker
                    });
                } catch (error) {
                    console.error('Map initialization error:', error);
                    this.error = 'Failed to initialize map: ' + error.message;
                }
            }, 200); // Increased timeout
        },

        displayFencesOnMap() {
            if (!this._mapInstance) return;

            // Clear existing fence markers
            if (this._fenceMarkers) {
                this._fenceMarkers.forEach(marker => this._mapInstance.removeLayer(marker));
                this._fenceMarkers = [];
            }

            // Add fence markers
            this.activeFences.forEach(fence => {
                if (!fence.active) return;

                let fenceLayer;

                if (fence.type === 'circle') {
                    fenceLayer = L.circle(
                        [fence.geometry.center.lat, fence.geometry.center.lng],
                        {
                            radius: fence.geometry.radius,
                            className: 'fence-circle',
                            fillOpacity: 0.2,
                            color: '#4299e1'
                        }
                    ).addTo(this._mapInstance);
                } else if (fence.type === 'polygon') {
                    fenceLayer = L.polygon(fence.polygonCoordinates, {
                        className: 'fence-polygon',
                        fillOpacity: 0.2,
                        color: '#ed8936'
                    }).addTo(this._mapInstance);
                }

                if (fenceLayer) {
                    fenceLayer.bindPopup(`<strong>${fence.name}</strong><br>Type: ${fence.type}`);
                    this._fenceMarkers.push(fenceLayer);
                }
            });
        },

        async handlePosition(position) {
            console.log('Position received:', position);
            const coords = position.coords;

            const location = {
                latitude: coords.latitude,
                longitude: coords.longitude,
                accuracy: coords.accuracy,
                altitude: coords.altitude,
                heading: coords.heading,
                speed: coords.speed,
                timestamp: new Date().toISOString()
            };

            // Update current location for display
            this.currentLocation = location;
            console.log('Current location updated:', location);

            // Update map position with smooth animation
            if (this._mapInstance && this._positionMarker) {
                console.log('Updating map position...');
                const newLatLng = L.latLng(location.latitude, location.longitude);

                // Animate marker to new position
                this._positionMarker.setLatLng(newLatLng);

                // Pan map to new location
                this._mapInstance.panTo(newLatLng);

                // Update popup content
                this._positionMarker.setPopupContent(
                    `<strong>Current Location</strong><br>
                Lat: ${location.latitude.toFixed(6)}<br>
                Lng: ${location.longitude.toFixed(6)}<br>
                Accuracy: ${location.accuracy}m`
                );
            } else {
                console.warn('Map or marker not ready:', {
                    map: !!this._mapInstance,
                    marker: !!this._positionMarker
                });
            }

            try {
                // Simulate server API call
                const mockResponse = this.simulateServerResponse(location);

                if (mockResponse.events && mockResponse.events.length > 0) {
                    this.handleFenceEvents(mockResponse.events);
                }

                if (mockResponse.debug && this.settings.showDebug) {
                    console.log(`Simulated movement: ${mockResponse.debug}`);
                }

            } catch (error) {
                console.error('Position handling error:', error);
                this.error = `Location update failed: ${error.message}`;
            }
        },

        // Simulate server response for demo purposes
        simulateServerResponse(location) {
            const events = [];

            // Simple distance-based fence checking for demo
            this.activeFences.forEach(fence => {
                if (!fence.active) return;

                if (fence.type === 'circle') {
                    const distance = this.calculateDistance(
                        location.latitude, location.longitude,
                        fence.geometry.center.lat, fence.geometry.center.lng
                    );

                    // Simulate entry/exit events
                    if (distance <= fence.geometry.radius && Math.random() < 0.1) {
                        events.push({
                            fenceId: fence.id,
                            fence_name: fence.name,
                            eventType: 'enter',
                            latitude: location.latitude,
                            longitude: location.longitude,
                            timestamp: new Date().toISOString()
                        });
                    }
                }
            });

            return {
                events: events,
                debug: `Position updated: ${location.latitude.toFixed(6)}, ${location.longitude.toFixed(6)}`
            };
        },

        calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371e3; // Earth's radius in meters
            const φ1 = lat1 * Math.PI/180;
            const φ2 = lat2 * Math.PI/180;
            const Δφ = (lat2-lat1) * Math.PI/180;
            const Δλ = (lon2-lon1) * Math.PI/180;

            const a = Math.sin(Δφ/2) * Math.sin(Δφ/2) +
                Math.cos(φ1) * Math.cos(φ2) *
                Math.sin(Δλ/2) * Math.sin(Δλ/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));

            return R * c;
        },

        handleFenceEvents(newEvents) {
            newEvents.forEach(event => {
                event.timestamp = new Date(event.timestamp).toLocaleString();
                this.events.unshift(event);

                if (this.settings.enableNotifications) {
                    this.showNotification(event);
                }
            });

            // Keep only last 50 events
            if (this.events.length > 50) {
                this.events = this.events.slice(0, 50);
            }
        },

        showNotification(event) {
            if (!('Notification' in window)) return;

            if (Notification.permission === 'granted') {
                const title = event.eventType === 'enter' ?
                    '📍 Entered Geo-Fence' : '🚪 Exited Geo-Fence';

                new Notification(title, {
                    body: `${event.fence_name || 'Fence #' + event.fenceId}`,
                    icon: '/favicon.ico',
                    tag: `fence-${event.fenceId}`,
                    requireInteraction: false
                });
            } else if (Notification.permission !== 'denied') {
                Notification.requestPermission();
            }
        },

        async loadActiveFences() {
            try {
                // Initialize sample fences for demo after component is ready
                this.activeFences = [
                    {
                        id: 1,
                        name: 'Home Zone',
                        type: 'circle',
                        active: true,
                        geometry: {
                            center: { lat: 40.7128, lng: -74.0060 },
                            radius: 100
                        }
                    },
                    {
                        id: 2,
                        name: 'Work Area',
                        type: 'polygon',
                        active: true,
                        polygonCoordinates: [
                            [40.7589, -73.9851],
                            [40.7614, -73.9776],
                            [40.7505, -73.9744],
                            [40.7481, -73.9825]
                        ]
                    }
                ];

                console.log('Loaded fences:', this.activeFences);
            } catch (error) {
                this.error = `Failed to load geo-fences: ${error.message}`;
            }
        },

        // Event handlers for foreach bindings
        removeEvent(event, index) {
            this.events.splice(index, 1);
        },

        async toggleFence(fence, index) {
            try {
                // Toggle fence active state
                fence.active = !fence.active;

                // Update map display
                this.displayFencesOnMap();

                console.log(`Fence ${fence.name} ${fence.active ? 'enabled' : 'disabled'}`);
            } catch (error) {
                this.error = `Failed to toggle fence: ${error.message}`;
            }
        },

        // Utility methods
        clearEvents() {
            this.events = [];
        },

        clearError() {
            this.error = null;
        },

        toggleSettings() {
            this.showSettings = !this.showSettings;
        },

        onEventsUpdated(events, meta) {
            if (meta.element && events.length > 0) {
                meta.element.scrollTop = 0;
            }
        },

        getLocationOptions() {
            const modeSettings = {
                high: {enableHighAccuracy: true, timeout: 5000, maximumAge: 0},
                balanced: {enableHighAccuracy: true, timeout: 10000, maximumAge: 30000},
                power: {enableHighAccuracy: false, timeout: 15000, maximumAge: 60000}
            };

            return modeSettings[this.settings.updateMode] || modeSettings.balanced;
        },

        handleLocationError(error) {
            let message = 'Unknown geolocation error';

            switch (error.code) {
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

            this.error = message;
            this.tracking = false;
        }
    });

    // Initialize map on page load
    document.addEventListener('DOMContentLoaded', () => {
        // Map will be initialized when tracking starts
        console.log('Geofencing dashboard loaded');
    });
</script>
</body>
</html>
{/literal}