# canvas-rabbitmq

RabbitMQ queue consumer and job dispatcher for the [Canvas PHP framework](https://canvasphp.com).

Adds a RabbitMQ-backed job queue to Canvas's Task Scheduler system. Jobs are dispatched from application code via
dependency injection and processed by a long-running worker process.

## Requirements

- PHP 8.2+
- Canvas 1.x
- RabbitMQ server
- [php-amqplib](https://github.com/php-amqplib/php-amqplib) (installed automatically)

## Installation

```bash
composer require quellabs/canvas-scheduler-rabbitmq
```

No further configuration is required. The package registers itself automatically via Canvas's service discovery.

## Configuration

Set the default queue driver in `config/app.php`:

```php
return [
    'queue_driver' => 'rabbitmq',
];
```

This tells Canvas to inject `RabbitMQQueue` by default when `QueueInterface` is requested. If you only have one queue
package installed, this can be omitted.

To request a specific driver regardless of the default, use contextual DI:

```php
$queue = $container->for('rabbitmq')->get(QueueInterface::class);
```

RabbitMQ-specific settings go in `config/scheduler-rabbitmq.php`:

```php
return [
    'host'           => '127.0.0.1',
    'port'           => 5672,
    'user'           => 'guest',
    'password'       => 'guest',
    'vhost'          => '/',
    'queue_name'     => 'default',
    'exchange_name'  => '',
    'queue_max_jobs' => 500,
    'prefetch_count' => 1,
];
```

`exchange_name` defaults to the empty string, which uses RabbitMQ's built-in default exchange and routes messages
directly to the queue by name. Set this only if you are using a custom exchange topology.

`prefetch_count` controls how many unacknowledged messages each worker holds at once. The default of `1` gives the
fairest work distribution when running multiple worker processes.

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

`getTimeout()` returns the maximum number of seconds the job is expected to run. This value is stored in the job
envelope for observability but is not enforced by the worker itself. To enforce it, set `stopwaitsecs` in your
Supervisord configuration to the longest expected job duration across all job types (see below).

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

The job is published to RabbitMQ immediately and processed by the worker when it is next available.

## Running the Worker

Start the worker via Sculpt:

```bash
./vendor/bin/sculpt schedule:run --consumer=rabbitmq
```

The worker polls RabbitMQ for jobs until it reaches the configured `queue_max_jobs` limit (default: 500), then exits
cleanly. Use [Supervisord](http://supervisord.org/) to keep it running:

```ini
[program:canvas-worker]
command = php /path/to/your/project/vendor/bin/sculpt schedule:run --consumer=rabbitmq
directory = /path/to/your/project
autostart = true
autorestart = true
numprocs = 2
user = www-data
stdout_logfile = /var/log/canvas-worker.log
stderr_logfile = /var/log/canvas-worker-error.log
stopwaitsecs = 60
```

`stopwaitsecs` should be set to the longest expected job duration across all job types. Supervisord will send `SIGKILL`
to any worker that has not stopped within this window after receiving `SIGTERM`.

Restart workers after deployment so they pick up new code:

```bash
supervisorctl restart canvas-worker:*
```

## Retry and Failure Handling

Failed jobs are retried up to `getMaxRetries()` times. On each failure the original message is rejected and a fresh
message with an incremented attempt counter is published back to the pending queue. Jobs that exhaust all retries are
published to a separate failed queue for inspection.

## Queue Layout

| Queue                 | Purpose      |
|-----------------------|--------------|
| `{queue_name}`        | Pending jobs |
| `{queue_name}.failed` | Failed jobs  |

Both queues are declared durable and messages are published as persistent, so jobs survive a RabbitMQ broker restart.

## License

MIT