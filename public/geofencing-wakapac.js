// assets/js/geofencing-wakapac.js
const geofencingApp = wakaPAC('#geofencing-app', {
    // State
    tracking: false,
    currentLocation: null,
    // Removed: lastUpdate: null, (conflicts with computed property)
    events: [],
    activeFences: [],
    error: null,
    showSettings: false,
    watchId: null,

    // Settings
    settings: {
        updateMode: 'balanced',
        enableNotifications: true,
        apiBase: '/api',
        showDebug: false // Optional debug mode
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
        if (!navigator.geolocation) {
            this.error = 'Geolocation not supported by this browser';
            return;
        }

        try {
            this.tracking = true;
            this.error = null;

            // Request permission first
            const permission = await navigator.permissions.query({name: 'geolocation'});
            if (permission.state === 'denied') {
                throw new Error('Location permission denied');
            }

            // Start watching position
            this.watchId = navigator.geolocation.watchPosition(
                (position) => this.handlePosition(position),
                (error) => this.handleLocationError(error),
                this.getLocationOptions()
            );

            // Load active fences
            await this.loadActiveFences();

        } catch (error) {
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

    async handlePosition(position) {
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

        // Always update current location for display
        this.currentLocation = location;

        try {
            // Access control method from the global component instance
            const response = await this.control(`${this.settings.apiBase}/location/update`, {
                method: 'POST',
                data: location,
                onError: (error) => {
                    this.error = `Failed to update location: ${error.message}`;
                }
            });

            // Server handles all logic and returns relevant events
            if (response && response.events && response.events.length > 0) {
                this.handleFenceEvents(response.events);
            }

            // Optional: Show debug info if server provides it
            if (response && response.debug && this.settings.showDebug) {
                console.log(`Moved ${response.distance_moved}m (min: ${response.min_distance_threshold}m)`);
            }

        } catch (error) {
            this.error = `Location update failed: ${error.message}`;
        }
    },

    handleFenceEvents(newEvents) {
        newEvents.forEach(event => {
            // Add timestamp for display
            event.timestamp = new Date(event.timestamp).toLocaleString();

            // Add to events list (newest first)
            this.events.unshift(event);

            // Show notification
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
        if (!('Notification' in window)) {
            return;
        }

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
            const fences = await this.control(`${this.settings.apiBase}/geofences/`, {
                method: 'GET',
                onError: (error) => {
                    this.error = `Failed to load fences: ${error.message}`;
                }
            });

            this.activeFences = fences || [];

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
            const updated = await this.control(`${this.settings.apiBase}/geofences/${fence.id}`, {
                method: 'PATCH',
                data: {active: !fence.active}
            });

            // Update local state
            if (updated) {
                this.activeFences[index] = updated;
            }

        } catch (error) {
            this.error = `Failed to toggle fence: ${error.message}`;
        }
    },

    // Utility methods (removed calculateDistance - now handled server-side)
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
        // Scroll to top when new events are added
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