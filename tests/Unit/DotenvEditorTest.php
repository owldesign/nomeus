<?php

use App\Support\DotenvEditor;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/nomeus-dotenv-'.uniqid();
    mkdir($this->dir);
    $this->file = "{$this->dir}/.env";
});

afterEach(function () {
    @unlink($this->file); @unlink("{$this->dir}/.env.example"); @rmdir($this->dir);
});

it('replaces the first definition in place and appends new keys under a header', function () {
    file_put_contents($this->file, "APP_NAME=Laravel\n# mail\nMAIL_MAILER=log\nMAIL_HOST=127.0.0.1\nMAIL_MAILER=other\nexport SESSION_DRIVER=file\n");

    $r = DotenvEditor::apply($this->file, ['MAIL_MAILER' => 'smtp', 'MAIL_PORT' => '1025', 'SESSION_DRIVER' => 'redis', 'APP_NAME' => 'Laravel']);

    expect($r)->toBe(['changed' => ['MAIL_MAILER', 'SESSION_DRIVER'], 'added' => ['MAIL_PORT'], 'created' => false])
        ->and(file_get_contents($this->file))->toBe(
            "APP_NAME=Laravel\n# mail\nMAIL_MAILER=smtp\nMAIL_HOST=127.0.0.1\nMAIL_MAILER=other\nexport SESSION_DRIVER=redis\n\n# nomeus\nMAIL_PORT=1025\n"
        );
});

it('quotes values with spaces or hashes and leaves quoted or interpolated ones alone', function () {
    expect(DotenvEditor::quote('Smoke App'))->toBe('"Smoke App"')
        ->and(DotenvEditor::quote('a#b'))->toBe('"a#b"')
        ->and(DotenvEditor::quote('"${APP_NAME}"'))->toBe('"${APP_NAME}"')
        ->and(DotenvEditor::quote("'x'"))->toBe("'x'")
        ->and(DotenvEditor::quote('plain'))->toBe('plain')
        ->and(DotenvEditor::quote(''))->toBe('');
});

it('creates .env from .env.example when missing', function () {
    file_put_contents("{$this->dir}/.env.example", "APP_NAME=Laravel\nAPP_KEY=\n");

    $r = DotenvEditor::apply($this->file, ['APP_NAME' => 'smoke']);

    expect($r['created'])->toBeTrue()
        ->and(file_get_contents($this->file))->toBe("APP_NAME=smoke\nAPP_KEY=\n");
});
