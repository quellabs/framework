<?php
	
	namespace App\Inspector;
	
	use Quellabs\Contracts\Inspector\{EventCollectorInterface, InspectorPanelInterface};
	use Symfony\Component\HttpFoundation\Request;
	
	class GeoLocationPanel implements InspectorPanelInterface {
		
		private array $locationEvents = [];
		private EventCollectorInterface $collector;
		
		public function __construct(EventCollectorInterface $collector) {
			$this->collector = $collector;
		}
		
		public function getSignalPatterns(): array {
			return ['debug.geofencing.*', 'fence.entered', 'fence.exited'];
		}
		
		public function processEvents(): void {
			$this->locationEvents = $this->collector->getEventsBySignals($this->getSignalPatterns());
		}
		
		public function getName(): string {
			return 'geolocation';
		}
		
		public function getTabLabel(): string {
			return 'Location (' . count($this->locationEvents) . ')';
		}
		
		public function getIcon(): string {
			return '📍';
		}
		
		public function getData(Request $request): array {
			return [
				'events'       => $this->locationEvents,
				'fence_events' => array_filter($this->locationEvents, fn($e) => str_contains($e['signal'], 'fence.')
				)
			];
		}
		
		public function getStats(): array {
			$fenceEvents = array_filter($this->locationEvents, fn($e) => str_contains($e['signal'], 'fence.')
			);
			
			return [
				'fence_triggers' => count($fenceEvents)
			];
		}
		
		public function getJsTemplate(): string {
			return <<<'JS'
return `
    <div class="debug-panel-section">
        <h3>Geo-Fencing Events</h3>
        <div class="canvas-debug-item-list">
            ${data.fence_events.map(event => `
                <div class="canvas-debug-item">
                    <div class="canvas-debug-item-header">
                        <span class="canvas-debug-status-badge ${event.data.eventType === 'enter' ? 'success' : 'warning'}">
                            ${event.data.eventType.toUpperCase()}
                        </span>
                        <span class="canvas-debug-text-mono">${event.data.fence_name || 'Fence #' + event.data.fenceId}</span>
                    </div>
                    <div class="canvas-debug-item-content">
                        <div class="canvas-debug-info-grid">
                            <div class="canvas-debug-info-item">
                                <span class="canvas-debug-label">User:</span>
                                <span class="canvas-debug-value">${event.data.userId}</span>
                            </div>
                            <div class="canvas-debug-info-item">
                                <span class="canvas-debug-label">Location:</span>
                                <span class="canvas-debug-value">${event.data.latitude.toFixed(6)}, ${event.data.longitude.toFixed(6)}</span>
                            </div>
                        </div>
                    </div>
                </div>
            `).join('')}
        </div>
    </div>
`;
JS;
		}
		
		public function getCss(): string {
			return <<<'CSS'
.canvas-debug-item.fence-enter {
    border-left: 4px solid #28a745;
}

.canvas-debug-item.fence-exit {
    border-left: 4px solid #ffc107;
}
CSS;
		}
	}