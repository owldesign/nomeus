<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MailpitClient;
use App\Services\ServiceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Proxies Mailpit's REST API so the dashboard stays same-origin. Reads only, except delete. */
class MailController extends Controller
{
    public function __construct(private readonly MailpitClient $mail) {}

    public function status(): JsonResponse
    {
        $i = $this->mail->instance();

        return response()->json(['data' => [
            'instance' => $i?->name,
            'available' => $this->mail->available(),
            'smtp_port' => $this->mail->smtpPort(),
            'http_port' => $this->mail->httpPort(),
            'ui_url' => $this->mail->baseUrl(),
            'env' => $i ? app(ServiceManager::class)->env($i) : null,
        ]]);
    }

    public function tags(): JsonResponse
    {
        return $this->guard(fn () => ['data' => $this->mail->tags()]);
    }

    public function messages(Request $request): JsonResponse
    {
        $tag = $request->query('tag');
        $start = max(0, (int) $request->query('start', 0));
        $limit = max(1, min(200, (int) $request->query('limit', 50)));

        return $this->guard(function () use ($tag, $start, $limit) {
            $page = $this->mail->messages($tag, $start, $limit);
            foreach ($page['messages'] as &$m) {
                $m['view_url'] = $this->mail->viewUrl($m['ID']);
            }

            return ['data' => $page];
        });
    }

    public function message(string $id): JsonResponse
    {
        return $this->guard(fn () => ['data' => $this->mail->message($id) + ['view_url' => $this->mail->viewUrl($id)]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $tag = $request->query('tag');

        return $this->guard(fn () => ['deleted' => $this->mail->deleteAll($tag), 'tag' => $tag]);
    }

    private function guard(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }
}
