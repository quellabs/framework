<?php
	
	namespace Quellabs\Canvas\Messaging;
	
	use Quellabs\SignalHub\HasSignals;
	
	/**
	 * This class provides standardized Slack integration
	 */
	class SlackMessenger {
		use HasSignals;
		
		private string $webhookUrl;
		private string $channel;
		private string $defaultUsername;
		private string $icon;
		private int $timeout;
		
		/**
		 * Constructor
		 * @param string $webhookUrl Slack webhook URL
		 * @param string $channel Default channel to send to (e.g., '#general')
		 * @param string $username Default username for messages
		 * @param string $icon Default icon/emoji for messages
		 * @param int $timeout HTTP timeout in seconds
		 */
		public function __construct(
			string $webhookUrl,
			string $channel = '#general',
			string $username = 'SignalBot',
			string $icon = ':robot_face:',
			int    $timeout = 10
		) {
			$this->webhookUrl = $webhookUrl;
			$this->channel = $channel;
			$this->defaultUsername = $username;
			$this->icon = $icon;
			$this->timeout = $timeout;
		}
		
		/**
		 * Simple message slot - accepts just a message string
		 * Compatible with signals: ['string']
		 * @param string $message Message to send
		 * @throws \JsonException|\InvalidArgumentException
		 */
		public function sendMessage(string $message): void {
			$this->sendToSlack([
				'text'       => $message,
				'channel'    => $this->channel,
				'username'   => $this->defaultUsername,
				'icon_emoji' => $this->icon
			]);
		}
		
		/**
		 * Detailed message slot - accepts message with channel and username
		 * Compatible with signals: ['string', 'string', 'string']
		 * @param string $message Message text
		 * @param string $channel Channel to send to (with # prefix)
		 * @param string $username Username to display
		 * @throws \JsonException|\InvalidArgumentException
		 */
		public function sendDetailedMessage(string $message, string $channel, string $username): void {
			$this->sendToSlack([
				'text'       => $message,
				'channel'    => $channel,
				'username'   => $username,
				'icon_emoji' => $this->icon
			]);
		}
		
		/**
		 * Error notification slot - sends formatted error messages
		 * Compatible with signals: ['string', 'string']
		 * @param string $errorMessage Error message
		 * @param string $context Additional context (e.g., class name, method)
		 * @throws \JsonException|\InvalidArgumentException
		 */
		public function sendError(string $errorMessage, string $context): void {
			$formattedMessage = "🚨 *Error in {$context}*\n```{$errorMessage}```";
			
			$this->sendToSlack([
				'text'        => $formattedMessage,
				'channel'     => $this->channel,
				'username'    => 'ErrorBot',
				'icon_emoji'  => ':warning:',
				'attachments' => [
					[
						'color'  => 'danger',
						'fields' => [
							[
								'title' => 'Context',
								'value' => $context,
								'short' => true
							],
							[
								'title' => 'Timestamp',
								'value' => date('Y-m-d H:i:s'),
								'short' => true
							]
						]
					]
				]
			]);
		}
		
		/**
		 * Status update slot - sends status messages with colors
		 * Compatible with signals: ['string', 'string']
		 * @param string $status Status message
		 * @param string $level Status level: 'info', 'success', 'warning', 'error'
		 * @throws \JsonException|\InvalidArgumentException
		 */
		public function sendStatus(string $status, string $level): void {
			$colors = [
				'info'    => '#36a64f',
				'success' => 'good',
				'warning' => 'warning',
				'error'   => 'danger'
			];
			
			$icons = [
				'info'    => ':information_source:',
				'success' => ':white_check_mark:',
				'warning' => ':warning:',
				'error'   => ':x:'
			];
			
			$icon = $icons[$level] ?? ':bell:';
			$color = $colors[$level] ?? 'good';
			
			$this->sendToSlack([
				'text'        => "{$icon} Status Update",
				'channel'     => $this->channel,
				'username'    => $this->defaultUsername,
				'icon_emoji'  => $this->icon,
				'attachments' => [
					[
						'color' => $color,
						'text'  => $status,
						'ts'    => time()
					]
				]
			]);
		}
		
		/**
		 * User action slot - logs user activities
		 * Compatible with signals: ['string', 'string']
		 * @param string $username Username who performed the action
		 * @param string $action Action that was performed
		 * @throws \JsonException|\InvalidArgumentException
		 */
		public function logUserAction(string $username, string $action): void {
			$message = "👤 *{$username}* {$action}";
			
			$this->sendToSlack([
				'text'       => $message,
				'channel'    => $this->channel,
				'username'   => 'ActivityBot',
				'icon_emoji' => ':bust_in_silhouette:'
			]);
		}
		
		/**
		 * Generic notification slot - accepts structured data
		 * Compatible with signals: ['string', 'string', 'string', 'array']
		 * @param string $title Notification title
		 * @param string $message Notification message
		 * @param string $color Color for the notification (good, warning, danger, or hex)
		 * @param array $fields Additional fields to display
		 * @throws \JsonException|\InvalidArgumentException
		 */
		public function sendNotification(string $title, string $message, string $color, array $fields): void {
			$payload = [
				'text'        => $title,
				'channel'     => $this->channel,
				'username'    => $this->defaultUsername,
				'icon_emoji'  => $this->icon,
				'attachments' => [
					[
						'color'  => $color,
						'text'   => $message,
						'fields' => $fields,
						'ts'     => time()
					]
				]
			];
			
			$this->sendToSlack($payload);
		}
		
		/**
		 * Debug message slot - sends debug information
		 * Compatible with signals: ['string', 'array']
		 * @param string $context Debug context
		 * @param array $data Debug data
		 * @throws \JsonException|\InvalidArgumentException
		 */
		public function sendDebug(string $context, array $data): void {
			$message = "🔍 *Debug: {$context}*\n```json\n" . json_encode($data, JSON_PRETTY_PRINT) . "\n```";
			
			$this->sendToSlack([
				'text'       => $message,
				'channel'    => $this->channel,
				'username'   => 'DebugBot',
				'icon_emoji' => ':mag:'
			]);
		}
		
		/**
		 * Send payload to Slack webhook
		 * @param array $payload Slack message payload
		 * @throws \RuntimeException If the request fails
		 * @throws \InvalidArgumentException|\JsonException If payload is invalid
		 */
		private function sendToSlack(array $payload): void {
			// Validate inputs
			if (empty($payload)) {
				throw new \InvalidArgumentException('Payload cannot be empty');
			}
			
			if (empty($this->webhookUrl) || !filter_var($this->webhookUrl, FILTER_VALIDATE_URL)) {
				throw new \InvalidArgumentException('Invalid or missing webhook URL');
			}
			
			// Prepare request
			$jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
			$timeout = max($this->timeout ?? 30, 1);
			
			// Execute request
			$response = $this->executeCurlRequest($jsonPayload, $timeout);
			
			// Validate Slack response
			if (trim($response) !== 'ok') {
				$responsePreview = substr(trim($response), 0, 100);
				throw new \RuntimeException("Slack webhook returned unexpected response: {$responsePreview}");
			}
		}
		
		/**
		 * Execute cURL request to Slack webhook
		 * @param string $jsonPayload JSON encoded payload
		 * @param int $timeout Request timeout in seconds
		 * @return string Response body
		 * @throws \RuntimeException If the request fails
		 */
		private function executeCurlRequest(string $jsonPayload, int $timeout): string {
			// Initialize cURL handle
			$ch = curl_init();
			
			// Check if cURL initialization was successful
			if ($ch === false) {
				throw new \RuntimeException('Failed to initialize cURL');
			}
			
			try {
				// Configure cURL options for the Slack webhook request
				$options = [
					CURLOPT_URL            => $this->webhookUrl,                // Set the webhook URL
					CURLOPT_POST           => true,                             // Use POST method
					CURLOPT_POSTFIELDS     => $jsonPayload,                     // Set the JSON payload as POST data
					CURLOPT_HTTPHEADER     => [
						'Content-Type: application/json',                       // Specify JSON content type
						'Content-Length: ' . strlen($jsonPayload)               // Set content length header
					],
					CURLOPT_RETURNTRANSFER => true,                             // Return response as string instead of outputting
					CURLOPT_TIMEOUT        => $timeout,                         // Set overall request timeout
					CURLOPT_CONNECTTIMEOUT => min($timeout, 10),        // Set connection timeout (max 10 seconds)
					CURLOPT_USERAGENT      => 'SignalHub-SlackMessenger/1.0',   // Set custom user agent
					CURLOPT_SSL_VERIFYPEER => true,                             // Verify SSL certificates for security
					CURLOPT_FOLLOWLOCATION => false,                            // Don't follow redirects
				];
				
				// Apply all cURL options at once
				if (!curl_setopt_array($ch, $options)) {
					throw new \RuntimeException('Failed to set cURL options');
				}
				
				// Execute the cURL request
				$response = curl_exec($ch);
				
				// Check if the request execution failed
				if ($response === false) {
					$error = curl_error($ch);      // Get the error message
					$errno = curl_errno($ch);      // Get the error number
					throw new \RuntimeException("cURL request failed: {$error} (Error code: {$errno})");
				}
				
				// Get HTTP status code from the response
				$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				
				// Check if the HTTP response indicates an error (not in 2xx range)
				if ($httpCode < 200 || $httpCode >= 300) {
					// Truncate response body for error message to avoid overly long exceptions
					$responseBody = is_string($response) ? substr($response, 0, 200) : 'No response body';
					throw new \RuntimeException("Slack API returned HTTP {$httpCode}. Response: {$responseBody}");
				}
				
				// Return the successful response body
				return $response;
				
			} finally {
				// Always clean up the cURL handle, even if an exception occurs
				if (is_resource($ch)) {
					curl_close($ch);
				}
			}
		}
	}