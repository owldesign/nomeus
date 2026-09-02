<?php

namespace App\Services\Dumps;

use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Cloner\Stub;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;

/**
 * One VarDumper server message → one store row. Plain dumps carry Symfony's own context
 * (source, request, cli); the client package's recorders add a `devkit` context with the kind.
 */
final class DumpIngest
{
    public const KINDS = ['dump', 'query', 'job', 'view', 'request', 'log'];

    private HtmlDumper $html;
    private CliDumper $cli;

    public function __construct()
    {
        $this->html = new HtmlDumper;
        $this->html->setDumpHeader('');   // the page includes the header (css + js) once
        $this->cli = new CliDumper;
        $this->cli->setColors(false);
    }

    /** Symfony's own header, for the page to include once. */
    public static function header(): string
    {
        return (new class extends HtmlDumper
        {
            public function header(): string
            {
                return $this->getDumpHeader();
            }
        })->header();
    }

    /** The wire format DumpServer receives: base64(serialize([Data, context])) per line. */
    public static function decode(string $line): ?array
    {
        $payload = @unserialize(base64_decode(trim($line)), ['allowed_classes' => [Data::class, Stub::class]]);
        if (! is_array($payload) || count($payload) < 2 || ! $payload[0] instanceof Data || ! is_array($payload[1])) {
            return null;
        }

        return [$payload[0], $payload[1]];
    }

    public function toRow(Data $data, array $context): array
    {
        $devkit = (array) ($context['devkit'] ?? []);
        $kind = in_array($devkit['kind'] ?? 'dump', self::KINDS, true) ? ($devkit['kind'] ?? 'dump') : 'dump';
        $request = (array) ($context['request'] ?? []);
        $cli = (array) ($context['cli'] ?? []);
        $source = (array) ($context['source'] ?? []);

        $text = $this->cli->dump($data, true);
        $row = [
            'kind' => $kind,
            'request_key' => $devkit['request_id'] ?? $request['identifier'] ?? $cli['identifier'] ?? null,
            'uri' => $request['uri'] ?? $devkit['uri'] ?? null,
            'method' => $request['method'] ?? $devkit['method'] ?? null,
            'command' => $cli['command_line'] ?? $devkit['command'] ?? null,
            'file' => $devkit['file'] ?? $source['file'] ?? null,
            'line' => isset($devkit['line']) ? (int) $devkit['line'] : (isset($source['line']) ? (int) $source['line'] : null),
            'text' => $kind === 'dump' ? $text : $this->summary($kind, $data),
            'html' => $kind === 'dump' ? $this->html->dump($data, true) : null,
            'payload' => $kind === 'dump' ? null : json_encode($this->value($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ];

        return $row;
    }

    /** Structured events arrive as arrays; give them a one-line text for the list and for search. */
    private function summary(string $kind, Data $data): string
    {
        $v = $this->value($data);
        if (! is_array($v)) {
            return (string) $v;
        }

        return match ($kind) {
            'query' => trim((string) ($v['sql'] ?? '')).(isset($v['ms']) ? "  — {$v['ms']} ms" : ''),
            'job' => trim(($v['status'] ?? '').' '.($v['name'] ?? '')).(isset($v['queue']) ? " on {$v['queue']}" : ''),
            'view' => (string) ($v['name'] ?? ''),
            'request' => trim(($v['method'] ?? '').' '.($v['url'] ?? '')).(isset($v['status']) ? " → {$v['status']}" : ''),
            'log' => strtoupper((string) ($v['level'] ?? '')).' '.($v['message'] ?? ''),
            default => json_encode($v),
        };
    }

    private function value(Data $data): mixed
    {
        return $data->getValue(true);
    }
}
