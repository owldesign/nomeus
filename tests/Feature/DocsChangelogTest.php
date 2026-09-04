<?php

use Illuminate\Support\Facades\Process;
use Tests\Support\FakeBrew;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nomeus-changelog-'.uniqid();
    mkdir($this->root, 0755, true);
    $this->brewFs = new FakeBrew;
    file_put_contents("{$this->root}/config.json", json_encode(['brew_prefix' => $this->brewFs->root]));
    config()->set('nomeus.config_path', "{$this->root}/config.json");
    $this->out = "{$this->root}/CHANGELOG.md";

    // Three tags; git for-each-ref sorts newest first. Subjects are what `git log --format=%s` prints.
    $this->fakeGit = function (array $ranges = [], string $tags = "v1.2.0 2026-09-03\nv1.1.0 2026-09-02\nv1.0.0 2026-09-01\n") {
        $subjects = $ranges + [
            'v1.2.0..HEAD' => "9a: ci, changelog\n",
            'v1.1.0..v1.2.0' => "7c: installer ui\nCreate CNAME\n7c: installer ui\n7d: mcp server\n",
            'v1.0.0..v1.1.0' => "phase-6a: doctor\n",
            'v1.0.0' => "phase-1a: skeleton\nfresh\n",
        ];
        // array commands match the fake as their quoted command line; dispatch on the argv instead
        Process::fake(['*git*' => fn ($p) => match ($p->command[1]) {
            'for-each-ref' => Process::result($tags),
            'log' => Process::result($subjects[end($p->command)] ?? ''),
            default => Process::result('', 'unexpected git call: '.implode(' ', $p->command), 1),
        }]);
    };
});

afterEach(function () {
    $this->brewFs->destroy();
    array_map('unlink', glob("{$this->root}/*") ?: []);
    rmdir($this->root);
});

it('generates one section per tag, oldest-to-newest bullets, duplicates and noise dropped', function () {
    ($this->fakeGit)();

    $this->artisan("docs:changelog --out={$this->out}")
        ->expectsOutputToContain('3 releases, 3 generated, 0 kept')
        ->assertSuccessful();

    $md = file_get_contents($this->out);
    expect($md)->toStartWith("# Changelog\n")
        ->and($md)->toContain("## [Unreleased](https://github.com/owldesign/nomeus/compare/v1.2.0...HEAD)\n\n- 9a: ci, changelog\n")
        ->and($md)->toContain("## [1.2.0](https://github.com/owldesign/nomeus/compare/v1.1.0...v1.2.0) - 2026-09-03\n\n- 7c: installer ui\n- 7d: mcp server\n")
        ->and($md)->not->toContain('Create CNAME')
        ->and($md)->toContain("## [1.1.0](https://github.com/owldesign/nomeus/compare/v1.0.0...v1.1.0) - 2026-09-02\n\n- phase-6a: doctor\n")
        ->and($md)->toContain("## [1.0.0] - 2026-09-01\n\n- phase-1a: skeleton\n")
        ->and($md)->not->toContain("- fresh\n");

    // order: Unreleased, 1.2.0, 1.1.0, 1.0.0
    expect(strpos($md, '[Unreleased]'))->toBeLessThan(strpos($md, '[1.2.0]'))
        ->and(strpos($md, '[1.2.0]'))->toBeLessThan(strpos($md, '[1.1.0]'))
        ->and(strpos($md, '[1.1.0]'))->toBeLessThan(strpos($md, '[1.0.0]'));
});

it('is idempotent and keeps a released section you edited by hand', function () {
    ($this->fakeGit)();
    $this->artisan("docs:changelog --out={$this->out}")->assertSuccessful();
    $first = file_get_contents($this->out);

    $this->artisan("docs:changelog --out={$this->out}")->expectsOutputToContain('0 generated, 3 kept')->assertSuccessful();
    expect(file_get_contents($this->out))->toBe($first);

    // hand-edit 1.1.0; a new commit lands on HEAD
    file_put_contents($this->out, str_replace('- phase-6a: doctor', "### Added\n- the doctor, sixty checks", $first));
    ($this->fakeGit)(['v1.2.0..HEAD' => "9a: ci, changelog\n9a: contributing\n"]);
    $this->artisan("docs:changelog --out={$this->out}")->assertSuccessful();

    $md = file_get_contents($this->out);
    expect($md)->toContain("### Added\n- the doctor, sixty checks")
        ->and($md)->not->toContain('phase-6a: doctor')
        ->and($md)->toContain("- 9a: ci, changelog\n- 9a: contributing\n");
});

it('omits Unreleased when HEAD is the latest tag', function () {
    ($this->fakeGit)(['v1.2.0..HEAD' => '']);
    $this->artisan("docs:changelog --out={$this->out}")->assertSuccessful();

    expect(file_get_contents($this->out))->not->toContain('## [Unreleased]')
        ->and(file_get_contents($this->out))->toContain('## [1.2.0]');
});

it('--next labels the unreleased commits as the coming version, and a later run keeps that section until the tag exists', function () {
    ($this->fakeGit)();
    $this->artisan("docs:changelog --out={$this->out} --next=2.0.0")->assertSuccessful();

    $md = file_get_contents($this->out);
    $today = date('Y-m-d');
    expect($md)->toContain("## [2.0.0](https://github.com/owldesign/nomeus/compare/v1.2.0...v2.0.0) - {$today}\n\n- 9a: ci, changelog\n")
        ->and($md)->not->toContain('## [Unreleased]');

    // still untagged: the 2.0.0 section survives a plain run (it is the pending release, not regenerated as Unreleased)
    ($this->fakeGit)(['v1.2.0..HEAD' => "9a: ci, changelog\n9a: v2.0.0\n"]);
    $this->artisan("docs:changelog --out={$this->out}")->assertSuccessful();
    $md = file_get_contents($this->out);
    expect($md)->toContain("## [2.0.0](https://github.com/owldesign/nomeus/compare/v1.2.0...v2.0.0) - {$today}")
        ->and($md)->not->toContain('9a: v2.0.0')
        ->and($md)->not->toContain('## [Unreleased]');

    // tagged: 2.0.0 is now a real tag, its section is kept verbatim; new commits become Unreleased
    ($this->fakeGit)(
        ['v2.0.0..HEAD' => "9b: polish\n", 'v1.2.0..v2.0.0' => "9a: ci, changelog\n9a: v2.0.0\n"],
        "v2.0.0 {$today}\nv1.2.0 2026-09-03\nv1.1.0 2026-09-02\nv1.0.0 2026-09-01\n",
    );
    $this->artisan("docs:changelog --out={$this->out}")->expectsOutputToContain('4 releases, 0 generated, 4 kept')->assertSuccessful();
    $md = file_get_contents($this->out);
    expect($md)->toContain("## [Unreleased](https://github.com/owldesign/nomeus/compare/v2.0.0...HEAD)\n\n- 9b: polish\n")
        ->and($md)->toContain("## [2.0.0](https://github.com/owldesign/nomeus/compare/v1.2.0...v2.0.0) - {$today}\n\n- 9a: ci, changelog\n")
        ->and(substr_count($md, '## [2.0.0]'))->toBe(1);
});

it('prints to stdout with --stdout and fails cleanly without tags', function () {
    ($this->fakeGit)();
    $this->artisan("docs:changelog --stdout --out={$this->out}")->expectsOutputToContain('## [1.2.0]')->assertSuccessful();
    expect(file_exists($this->out))->toBeFalse();

    ($this->fakeGit)([], '');
    $this->artisan("docs:changelog --out={$this->out}")->expectsOutputToContain('no tags found')->assertFailed();
});
