<?php

use App\Services\MailpitClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeServicesWorld;

beforeEach(function () {
    $this->w = new FakeServicesWorld;
    $this->w->manager->create('mailpit', start: false);            // 1025 / http 8025
    $this->w->answering = [8025];
    $this->client = new MailpitClient($this->w->manager, $this->w->config, $this->w->probe);
});

afterEach(fn () => $this->w->destroy());

it('addresses the devkit mailpit instance', function () {
    expect($this->client->instance()?->name)->toBe('mailpit')
        ->and($this->client->smtpPort())->toBe(1025)
        ->and($this->client->httpPort())->toBe(8025)
        ->and($this->client->baseUrl())->toBe('http://127.0.0.1:8025')
        ->and($this->client->available())->toBeTrue()
        ->and($this->client->viewUrl('abc'))->toBe('http://127.0.0.1:8025/view/abc.html');
});

it('reads tags, pages of messages (all or per tag), and one message', function () {
    Http::fake([
        '127.0.0.1:8025/api/v1/tags' => Http::response(['smoke', 'fsv']),
        '127.0.0.1:8025/api/v1/messages*' => Http::response(['total' => 2, 'unread' => 1, 'count' => 2, 'start' => 0, 'messages' => [['ID' => 'a', 'Subject' => 'one'], ['ID' => 'b', 'Subject' => 'two']]]),
        '127.0.0.1:8025/api/v1/search*' => Http::response(['total' => 1, 'unread' => 0, 'count' => 1, 'start' => 0, 'messages' => [['ID' => 'a', 'Subject' => 'one', 'Tags' => ['smoke']]]]),
        '127.0.0.1:8025/api/v1/message/a' => Http::response(['ID' => 'a', 'Subject' => 'one', 'HTML' => '<p>hi</p>']),
    ]);

    expect($this->client->tags())->toBe(['smoke', 'fsv'])
        ->and($this->client->messages()['total'])->toBe(2)
        ->and($this->client->messages('smoke')['messages'][0]['Tags'])->toBe(['smoke'])
        ->and($this->client->message('a')['HTML'])->toBe('<p>hi</p>');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/api/v1/search') && str_contains($r->url(), 'query=tag%3Asmoke') && str_contains($r->url(), 'limit=50'));
});

it('deletes everything, or every message with a tag by id', function () {
    Http::fake([
        '127.0.0.1:8025/api/v1/search*' => Http::sequence()
            ->push(['total' => 2, 'messages' => [['ID' => 'a'], ['ID' => 'b']]])
            ->push(['total' => 0, 'messages' => []]),
        '127.0.0.1:8025/api/v1/messages*' => Http::response(['total' => 7]),
    ]);

    expect($this->client->deleteAll('smoke'))->toBe(2);
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === 'http://127.0.0.1:8025/api/v1/messages' && $r->data() === ['IDs' => ['a', 'b']]);

    expect($this->client->deleteAll())->toBe(7);
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->body() === '{}');
});

it('explains a mailpit that is not answering', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(fn () => $this->client->tags())->toThrow(RuntimeException::class, 'not answering on http://127.0.0.1:8025 — devkit services:start mailpit');
});
