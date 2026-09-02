<?php

use App\Services\ServiceManager;
use App\Support\Probe;
use App\Support\TaskSpawner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;
use Tests\Support\FakeValet;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/devkit-mailapi-'.uniqid();
    mkdir("{$this->root}/devkit", 0755, true);
    mkdir("{$this->root}/agents", 0755, true);
    $this->brewFs = (new FakeBrew)->formula('mailpit', '1.31.0', ['mailpit']);
    file_put_contents("{$this->root}/devkit/config.json", json_encode(['brew_prefix' => $this->brewFs->root, 'mail' => ['smtp_port' => 1025, 'ui_port' => 8025]]));
    config()->set('devkit.config_path', "{$this->root}/devkit/config.json");
    config()->set('devkit.launch_agents_dir', "{$this->root}/agents");
    config()->set('devkit.uid', 501);
    $this->valetFs = new FakeValet;
    config()->set('devkit.valet_config_dir', $this->valetFs->configDir);
    config()->set('devkit.valet_bin', $this->valetFs->valetBin());

    // Down until "started": create() probes the ports first and would step past 1025/8025 if they answered.
    // The bootstrap fake flips it; tests that create with start:false flip it themselves.
    $this->up = false;
    $this->mock(Probe::class, function ($m) {
        $m->shouldReceive('tcp')->andReturnUsing(fn (string $h, int $p) => in_array($p, [1025, 8025], true) && $this->up);
        $m->shouldReceive('unix')->andReturn(false);
    });
    $this->mock(TaskSpawner::class, fn ($m) => $m->shouldReceive('spawn'));
    Process::fake([
        '*launchctl*print-disabled*' => Process::result(''),
        '*launchctl*print*' => Process::result('', '', 113),
        "*'launchctl' 'list'*" => Process::result(''),
        '*launchctl*bootstrap*' => function () {
            $this->up = true;   // launchd "started" it

            return Process::result('');
        },
        '*launchctl*' => Process::result(''),
        "*mailpit' 'version*" => Process::result("mailpit v1.31.0\n"),
        "*'open' *" => Process::result(''),   // never actually open a browser from the suite
        '*pgrep*' => Process::result('', '', 1),
        '*php*-r*' => Process::result('8.4.25'),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    $this->brewFs->destroy();
    $this->valetFs->destroy();
});

it('reports no instance, then the instance once created', function () {
    $this->getJson('/api/mail/status')->assertOk()->assertJsonPath('data.instance', null)->assertJsonPath('data.http_port', 8025);

    app(ServiceManager::class)->create('mailpit', start: false);
    $this->up = true;
    $this->getJson('/api/mail/status')->assertOk()
        ->assertJsonPath('data.instance', 'mailpit')
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.smtp_port', 1025)
        ->assertJsonPath('data.ui_url', 'http://127.0.0.1:8025')
        ->assertJsonPath('data.env.MAIL_PORT', '1025');
});

it('proxies tags, messages and one message, adding view urls', function () {
    app(ServiceManager::class)->create('mailpit', start: false);
    $this->up = true;
    Http::fake([
        '127.0.0.1:8025/api/v1/tags' => Http::response(['smoke']),
        '127.0.0.1:8025/api/v1/search*' => Http::response(['total' => 1, 'unread' => 1, 'count' => 1, 'start' => 0, 'messages' => [['ID' => 'a', 'Subject' => 'hi', 'Tags' => ['smoke']]]]),
        '127.0.0.1:8025/api/v1/messages*' => Http::response(['total' => 1, 'unread' => 1, 'count' => 1, 'start' => 0, 'messages' => [['ID' => 'a', 'Subject' => 'hi', 'Tags' => ['smoke']]]]),
        '127.0.0.1:8025/api/v1/message/a' => Http::response(['ID' => 'a', 'Subject' => 'hi']),
    ]);

    $this->getJson('/api/mail/tags')->assertOk()->assertJsonPath('data', ['smoke']);
    $this->getJson('/api/mail/messages?tag=smoke')->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.messages.0.view_url', 'http://127.0.0.1:8025/view/a.html');
    $this->getJson('/api/mail/messages')->assertOk()->assertJsonPath('data.messages.0.ID', 'a');
    $this->getJson('/api/mail/messages/a')->assertOk()->assertJsonPath('data.view_url', 'http://127.0.0.1:8025/view/a.html');
});

it('deletes with the header guard, and answers 503 when mailpit is down', function () {
    app(ServiceManager::class)->create('mailpit', start: false);
    $this->up = true;
    Http::fake([
        '127.0.0.1:8025/api/v1/search*' => Http::sequence()->push(['total' => 1, 'messages' => [['ID' => 'a']]])->push(['total' => 0, 'messages' => []]),
        '127.0.0.1:8025/api/v1/messages*' => Http::response(['total' => 3]),
    ]);

    $this->deleteJson('/api/mail/messages?tag=smoke')->assertForbidden();
    $this->deleteJson('/api/mail/messages?tag=smoke', [], ['X-Devkit' => '1'])->assertOk()->assertJsonPath('deleted', 1);
    $this->deleteJson('/api/mail/messages', [], ['X-Devkit' => '1'])->assertOk()->assertJsonPath('deleted', 3);

    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));
    $this->getJson('/api/mail/tags')->assertStatus(503)->assertJsonPath('message', fn ($m) => str_contains($m, 'not answering'));
});

it('opens or creates mailpit from the cli', function () {
    $this->artisan('mail')->expectsOutputToContain('No mailpit instance')->assertFailed();

    $this->artisan('mail --create')->expectsOutputToContain('http://127.0.0.1:8025')->assertSuccessful();
    Process::assertRan(fn ($p) => $p->command === ['open', 'http://127.0.0.1:8025']);
    expect(app(ServiceManager::class)->find('mailpit'))->not->toBeNull();

    $this->up = false;   // instance exists but is stopped → mail starts it (the bootstrap fake brings it up)
    $this->artisan('mail')->expectsOutputToContain('starting mailpit')->assertSuccessful();
});
