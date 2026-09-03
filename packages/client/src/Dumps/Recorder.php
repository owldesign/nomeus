<?php

namespace Nomeus\Client\Dumps;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;

/** The Laravel hooks behind the Debug page's Queries / Jobs / Views / Requests / Logs tabs. */
final class Recorder
{
    /** @var array<string, float> outgoing http requests in flight, keyed by spl_object_id */
    private array $inflight = [];

    private array $jobStarted = [];

    public function __construct(private readonly Sender $sender, private readonly Application $app) {}

    public function register(): void
    {
        Event::listen(QueryExecuted::class, function (QueryExecuted $e) {
            $this->sender->send('query', [
                'sql' => $e->sql,
                'bindings' => $this->scalars($e->bindings),
                'ms' => round($e->time, 2),
                'connection' => $e->connectionName,
            ], $this->caller());
        });

        Event::listen(JobProcessing::class, function (JobProcessing $e) {
            $this->jobStarted[$e->job->getJobId() ?? spl_object_id($e->job)] = microtime(true);
            $this->sender->send('job', ['status' => 'processing', 'name' => $e->job->resolveName(), 'queue' => $e->job->getQueue(), 'connection' => $e->connectionName]);
        });
        Event::listen(JobProcessed::class, fn (JobProcessed $e) => $this->sender->send('job', [
            'status' => 'processed', 'name' => $e->job->resolveName(), 'queue' => $e->job->getQueue(), 'connection' => $e->connectionName,
            'ms' => $this->jobMs($e->job->getJobId() ?? spl_object_id($e->job)),
        ]));
        Event::listen(JobFailed::class, fn (JobFailed $e) => $this->sender->send('job', [
            'status' => 'failed', 'name' => $e->job->resolveName(), 'queue' => $e->job->getQueue(), 'connection' => $e->connectionName,
            'ms' => $this->jobMs($e->job->getJobId() ?? spl_object_id($e->job)), 'exception' => $e->exception->getMessage(),
        ]));

        View::composer('*', function ($view) {
            $this->sender->send('view', ['name' => $view->getName(), 'path' => $view->getPath()]);
        });

        Event::listen(RequestSending::class, function (RequestSending $e) {
            $this->inflight[spl_object_id($e->request)] = microtime(true);
        });
        Event::listen(ResponseReceived::class, function (ResponseReceived $e) {
            $started = $this->inflight[spl_object_id($e->request)] ?? null;
            unset($this->inflight[spl_object_id($e->request)]);
            $this->sender->send('request', [
                'method' => $e->request->method(),
                'url' => $e->request->url(),
                'status' => $e->response->status(),
                'ms' => $started ? round((microtime(true) - $started) * 1000, 1) : null,
                'response' => mb_strimwidth($e->response->body(), 0, 4000, '…'),
            ], $this->caller());
        });

        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->sender->send('log', ['level' => $e->level, 'message' => $e->message, 'context' => $this->scalars($e->context)]);
        });
    }

    /** First frame outside vendor/ — where the app made the call. */
    private function caller(): array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $file = $frame['file'] ?? '';
            if ($file !== '' && ! str_contains($file, '/vendor/') && ! str_contains($file, 'nomeus-client')) {
                return ['file' => $file, 'line' => $frame['line'] ?? null];
            }
        }

        return [];
    }

    private function jobMs(int|string $key): ?float
    {
        $started = $this->jobStarted[$key] ?? null;
        unset($this->jobStarted[$key]);

        return $started ? round((microtime(true) - $started) * 1000, 1) : null;
    }

    /** Bindings/context reduced to something serialisable and short. */
    private function scalars(array $values): array
    {
        return array_map(function ($v) {
            if ($v instanceof \DateTimeInterface) {
                return $v->format('Y-m-d H:i:s');
            }
            if (is_object($v)) {
                return method_exists($v, '__toString') ? (string) $v : get_class($v);
            }
            if (is_array($v)) {
                return $this->scalars($v);
            }

            return is_string($v) && strlen($v) > 500 ? substr($v, 0, 500).'…' : $v;
        }, $values);
    }
}
