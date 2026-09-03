<?php

namespace App\Services\Dumps;

use App\Support\NomeusConfig;
use PDO;

/** SQLite under ~/.nomeus/dumps — written by the server, read by the API and CLI. */
final class DumpStore
{
    public const KEEP = 5000;

    private ?PDO $pdo = null;

    public function __construct(private readonly NomeusConfig $config, private readonly ?string $file = null) {}

    public function path(): string
    {
        return $this->file ?? $this->config->dir().'/dumps/dumps.sqlite';
    }

    /**
     * @param  array{kind:string, request_key:?string, uri:?string, method:?string, command:?string, file:?string, line:?int, text:string, html:?string, payload:?string}  $row
     */
    public function insert(array $row): int
    {
        $this->pdo()->prepare('INSERT INTO entries (created_at, kind, request_key, uri, method, command, file, line, text, html, payload) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $row['created_at'] ?? now()->format('Y-m-d H:i:s.v'), $row['kind'], $row['request_key'], $row['uri'], $row['method'], $row['command'],
                $row['file'], $row['line'], $row['text'], $row['html'], $row['payload'],
            ]);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * afterId null → the newest $limit rows (ascending); otherwise rows with id > afterId.
     *
     * @return list<array>
     */
    public function page(?string $kind = null, ?string $requestKey = null, ?int $afterId = null, int $limit = 200): array
    {
        $where = [];
        $bind = [];
        if ($kind !== null && $kind !== '' && $kind !== 'all') {
            $where[] = 'kind = ?';
            $bind[] = $kind;
        }
        if ($requestKey !== null && $requestKey !== '') {
            $where[] = 'request_key = ?';
            $bind[] = $requestKey;
        }
        if ($afterId !== null) {
            $where[] = 'id > ?';
            $bind[] = $afterId;
        }
        $sql = 'SELECT * FROM entries'.($where ? ' WHERE '.implode(' AND ', $where) : '');
        if ($afterId === null) {
            $sql .= ' ORDER BY id DESC LIMIT '.(int) $limit;
            $rows = array_reverse($this->query($sql, $bind));
        } else {
            $sql .= ' ORDER BY id ASC LIMIT '.(int) $limit;
            $rows = $this->query($sql, $bind);
        }

        return array_map(fn ($r) => $r + ['line' => $r['line'] !== null ? (int) $r['line'] : null, 'id' => (int) $r['id']], $rows);
    }

    /** @return array<string,int> kind => count */
    public function counts(?string $requestKey = null): array
    {
        $rows = $requestKey
            ? $this->query('SELECT kind, COUNT(*) AS n FROM entries WHERE request_key = ? GROUP BY kind', [$requestKey])
            : $this->query('SELECT kind, COUNT(*) AS n FROM entries GROUP BY kind');
        $out = [];
        foreach ($rows as $r) {
            $out[$r['kind']] = (int) $r['n'];
        }

        return $out;
    }

    /** Recent requests/commands, newest first. @return list<array{request_key:string, uri:?string, method:?string, command:?string, first:string, last_id:int, n:int}> */
    public function requests(int $limit = 50): array
    {
        return array_map(fn ($r) => $r + ['last_id' => (int) $r['last_id'], 'n' => (int) $r['n']], $this->query(
            'SELECT request_key, MAX(uri) AS uri, MAX(method) AS method, MAX(command) AS command, MIN(created_at) AS first, MAX(id) AS last_id, COUNT(*) AS n
             FROM entries WHERE request_key IS NOT NULL GROUP BY request_key ORDER BY last_id DESC LIMIT '.(int) $limit
        ));
    }

    public function latestRequestKey(): ?string
    {
        $rows = $this->query('SELECT request_key FROM entries WHERE request_key IS NOT NULL ORDER BY id DESC LIMIT 1');

        return $rows[0]['request_key'] ?? null;
    }

    public function clear(): int
    {
        $n = (int) $this->query('SELECT COUNT(*) AS n FROM entries')[0]['n'];
        $this->pdo()->exec('DELETE FROM entries');

        return $n;
    }

    public function prune(int $keep = self::KEEP): void
    {
        $this->pdo()->exec('DELETE FROM entries WHERE id <= (SELECT MAX(id) FROM entries) - '.(int) $keep);
    }

    private function query(string $sql, array $bind = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($bind);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dir = dirname($this->path());
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $this->pdo = new PDO('sqlite:'.$this->path(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA busy_timeout=2000');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL, kind TEXT NOT NULL, request_key TEXT,
                uri TEXT, method TEXT, command TEXT, file TEXT, line INTEGER, text TEXT NOT NULL, html TEXT, payload TEXT)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS entries_kind ON entries (kind, id)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS entries_request ON entries (request_key, id)');
        }

        return $this->pdo;
    }
}
