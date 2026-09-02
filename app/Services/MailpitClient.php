<?php

namespace App\Services;

use App\Support\DevkitConfig;
use App\Support\Probe;
use App\Support\ServiceInstance;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mailpit's REST API, addressed at the devkit mailpit instance (or the configured UI port when
 * no instance exists). Per-app inboxes are Mailpit tags; the client package sets X-Tags on send.
 */
final class MailpitClient
{
    public function __construct(
        private readonly ServiceManager $services,
        private readonly DevkitConfig $config,
        private readonly Probe $probe,
    ) {}

    public function instance(): ?ServiceInstance
    {
        foreach ($this->services->all() as $i) {
            if ($i->type === 'mailpit') {
                return $i;
            }
        }

        return null;
    }

    public function httpPort(): int
    {
        return (int) ($this->instance()?->options['http_port'] ?? $this->config->get('mail.ui_port', 8025));
    }

    public function smtpPort(): int
    {
        return (int) ($this->instance()?->port ?? $this->config->get('mail.smtp_port', 1025));
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:'.$this->httpPort();
    }

    public function available(): bool
    {
        return $this->probe->tcp('127.0.0.1', $this->httpPort());
    }

    public function viewUrl(string $id): string
    {
        return "{$this->baseUrl()}/view/{$id}.html";
    }

    /** @return list<string> */
    public function tags(): array
    {
        return array_values((array) $this->get('/api/v1/tags'));
    }

    /**
     * @return array{total:int, unread:int, count:int, start:int, messages:list<array>}
     */
    public function messages(?string $tag = null, int $start = 0, int $limit = 50): array
    {
        $data = $tag !== null && $tag !== ''
            ? $this->get('/api/v1/search', ['query' => "tag:{$tag}", 'start' => $start, 'limit' => $limit])
            : $this->get('/api/v1/messages', ['start' => $start, 'limit' => $limit]);

        return [
            'total' => (int) ($data['total'] ?? 0),
            'unread' => (int) ($data['unread'] ?? 0),
            'count' => (int) ($data['count'] ?? count($data['messages'] ?? [])),
            'start' => (int) ($data['start'] ?? $start),
            'messages' => array_values((array) ($data['messages'] ?? [])),
        ];
    }

    public function message(string $id): array
    {
        return (array) $this->get('/api/v1/message/'.rawurlencode($id));
    }

    /** Everything, or every message carrying the tag. Returns how many Mailpit was asked to delete. */
    public function deleteAll(?string $tag = null): int
    {
        if ($tag === null || $tag === '') {
            $total = (int) ($this->get('/api/v1/messages', ['limit' => 1])['total'] ?? 0);
            $this->send('DELETE', '/api/v1/messages', []);

            return $total;
        }
        $ids = [];
        do {
            $page = $this->messages($tag, 0, 200);
            $batch = array_map(fn ($m) => $m['ID'], $page['messages']);
            if ($batch === []) {
                break;
            }
            $this->send('DELETE', '/api/v1/messages', ['IDs' => $batch]);
            $ids = array_merge($ids, $batch);
        } while (count($batch) === 200);

        return count($ids);
    }

    private function get(string $path, array $query = []): mixed
    {
        return $this->send('GET', $path, $query);
    }

    private function send(string $method, string $path, array $payload): mixed
    {
        try {
            $req = Http::baseUrl($this->baseUrl())->acceptJson()->timeout(10);
            $res = match ($method) {
                'GET' => $req->get($path, $payload),
                'DELETE' => $req->withBody(json_encode($payload ?: new \stdClass), 'application/json')->delete($path),
                default => throw new RuntimeException("unsupported {$method}"),
            };
            $res->throw();

            return $res->json();
        } catch (RequestException $e) {
            throw new RuntimeException("Mailpit {$method} {$path}: HTTP {$e->response->status()} — ".trim($e->response->body()));
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RuntimeException("Mailpit is not answering on {$this->baseUrl()} — devkit services:start ".($this->instance()?->name ?? 'mailpit').' (or services:create mailpit)');
        }
    }
}
