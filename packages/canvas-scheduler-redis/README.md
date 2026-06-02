# canvas-redis

Redis queue consumer and job dispatcher for the [Canvas PHP framework](https://canvasphp.com).

Adds a Redis-backed job queue to Canvas's Task Scheduler system. Jobs are dispatched from application code via
dependency injection and processed by a long-running worker process.

## Requirements

- PHP 8.2+
- Canvas 1.x
- Redis server
- [Predis](https://github.com/predis/predis) (installed automatically)

## Installation

```bash
composer require quellabs/canvas-redis
```

No further configuration is required. The package registers itself automatically via Canvas's service discovery.

## Configuration

Set the default queue driver in `config/app.php`:

```php
return [
    'queue_driver' => 'redis',
];
```

This tells Canvas to inject `RedisQueue` by default when `QueueInterface` is requested. If you only have one queue
package installed, this can be omitted.

To request a specific driver regardless of the default, use contextual DI:

```php
$queue = $container->for('redis')->get(QueueInterface::class);
```

Redis-specific settings go in `config/scheduler-redis.php`:

```php
return [
    'scheme'         => 'tcp',
    'host'           => '127.0.0.1',
    'port'           => 6379,
    'queue_name'     => 'default',
    'queue_prefix'   => 'canvas',
    'queue_max_jobs' => 500,
    'queue_timeout'  => 5,
];
```

## Creating a Job

Implement `QueueableInterface` on any class. Constructor parameters become the serializable payload:

```php
use Quellabs\Contracts\Scheduler\QueueableInterface;

class SendEmailJob implements QueueableInterface {

    public function __construct(
        private int    $userId,
        private string $template
    ) {}

    public function handle(): void {
        // Send the email
    }

    public function getPayload(): array {
        return [
            'userId'   => $this->userId,
            'template' => $this->template,
        ];
    }

    public function getTimeout(): int {
        return 30;
    }

    public function getMaxRetries(): int {
        return 3;
    }
}
```

Payload keys must match constructor parameter names exactly — the worker reconstructs the job via Canvas's DI container
using `make($class, $payload)`.

## Dispatching Jobs

Inject `QueueInterface` into any controller or service:

```php
use Quellabs\Contracts\Scheduler\QueueInterface;

class UserController {

    public function __construct(private QueueInterface $queue) {}

    public function register(Request $request): Response {
        // Handle registration...

        $this->queue->push(new SendEmailJob(
            userId: $user->id,
            template: 'welcome'
        ));

        return new Response('Registered');
    }
}
```

The job is pushed onto the Redis list immediately and processed by the worker when it is next available.

## Running the Worker

Start the worker via Sculpt:

```bash
./vendor/bin/sculpt schedule:run --consumer=redis
```

The worker processes jobs until it reaches the configured `queue_max_jobs` limit (default: 500), then exits cleanly.
Use [Supervisord](http://supervisord.org/) to keep it running:

```ini
[program:canvas-worker]
command = php /path/to/your/project/vendor/bin/sculpt schedule:run --consumer=redis
directory = /path/to/your/project
autostart = true
autorestart = true
numprocs = 2
user = www-data
stdout_logfile = /var/log/canvas-worker.log
stderr_logfile = /var/log/canvas-worker-error.log
stopwaitsecs = 30
```

Restart workers after deployment so they pick up new code:

```bash
supervisorctl restart canvas-worker:*
```

## Retry and Failure Handling

Failed jobs are retried up to `getMaxRetries()` times. Each retry increments the attempt counter and requeues the job.
Jobs that exhaust all retries are moved to a failed list in Redis at:

```
canvas:failed:default
```

## Redis Key Layout

| Key                             | Purpose                 |
|---------------------------------|-------------------------|
| `{prefix}:queue:{name}`         | Pending jobs            |
| `{prefix}:failed:{name}`        | Failed jobs             |
| `{prefix}:reserved:{name}:{id}` | Currently executing job |

## License

MIT