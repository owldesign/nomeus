<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * A Homebrew prefix look-alike: bin/brew stub, Cellar/php@X.Y/<patch>, opt/php@X.Y symlinks,
 * opt/php → the linked keg, etc/php/X.Y, and the shivammathur tap's Formula dir.
 * Point config.json's brew_prefix at ->root and Shell::brewPrefix() uses it.
 */
final class FakeBrew
{
    public readonly string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/nomeus-brew-'.uniqid();
        foreach (['bin', 'opt', 'Cellar', 'etc/php', 'Library/Taps/shivammathur/homebrew-php/Formula'] as $d) {
            mkdir("{$this->root}/$d", 0755, true);
        }
        file_put_contents("{$this->root}/bin/brew", "#!/bin/sh\necho stub-brew\n");
        chmod("{$this->root}/bin/brew", 0755);
    }

    /** $formula lets a version live under another Cellar name — core's `php` is aliased php@8.5. */
    public function installed(string $version, string $patch, ?string $formula = null): self
    {
        $keg = "{$this->root}/Cellar/".($formula ?? "php@{$version}")."/{$patch}";
        mkdir("$keg/bin", 0755, true);
        file_put_contents("$keg/bin/php", "#!/bin/sh\necho {$patch}\n");
        chmod("$keg/bin/php", 0755);
        if (! is_link("{$this->root}/opt/php@{$version}")) {
            symlink($keg, "{$this->root}/opt/php@{$version}");
        }
        mkdir("{$this->root}/etc/php/{$version}/conf.d", 0755, true);
        touch("{$this->root}/etc/php/{$version}/php.ini");

        return $this;
    }

    public function linked(string $version): self
    {
        $target = readlink("{$this->root}/opt/php@{$version}");
        @unlink("{$this->root}/opt/php");
        symlink($target, "{$this->root}/opt/php");
        @unlink("{$this->root}/bin/php");
        symlink("$target/bin/php", "{$this->root}/bin/php");

        return $this;
    }

    /** @param  list<string>  $versions */
    public function available(array $versions): self
    {
        foreach ($versions as $v) {
            touch("{$this->root}/Library/Taps/shivammathur/homebrew-php/Formula/php@{$v}.rb");
        }
        // decoys the parser must skip
        touch("{$this->root}/Library/Taps/shivammathur/homebrew-php/Formula/php@8.4-debug.rb");
        touch("{$this->root}/Library/Taps/shivammathur/homebrew-php/Formula/php.rb");

        return $this;
    }

    /** Any formula with executable stubs, e.g. formula('postgresql@17', '17.6', ['initdb', 'postgres']). */
    public function formula(string $formula, string $version, array $bins): self
    {
        $short = basename($formula);
        $keg = "{$this->root}/Cellar/{$short}/{$version}";
        if (! is_dir("$keg/bin")) {
            mkdir("$keg/bin", 0755, true);
        }
        foreach ($bins as $bin) {
            file_put_contents("$keg/bin/$bin", "#!/bin/sh\necho stub-$bin\n");
            chmod("$keg/bin/$bin", 0755);
        }
        $link = "{$this->root}/opt/{$short}";
        if (is_link($link) && ! is_dir($link)) {
            unlink($link);                        // dangling (keg removed): re-point it, as brew would
        }
        if (! is_link($link)) {
            symlink($keg, $link);
        }

        return $this;
    }

    /** Remove a formula the way `brew uninstall` does: the opt link and the keg. */
    public function uninstall(string $formula): self
    {
        $short = basename($formula);
        @unlink("{$this->root}/opt/{$short}");
        File::deleteDirectory("{$this->root}/Cellar/{$short}");

        return $this;
    }

    public function destroy(): void
    {
        File::deleteDirectory($this->root);
    }
}
