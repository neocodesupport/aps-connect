<?php

declare(strict_types=1);

use Neocode\ApsConnect\Support\PhpSourceObfuscator;

function apsConnectWriteObfuscatorFixture(string $contents): string
{
    $path = sys_get_temp_dir().'/aps-connect-obfuscator-'.bin2hex(random_bytes(8)).'.php';
    file_put_contents($path, $contents);

    return $path;
}

beforeEach(function () {
    $this->fixturePaths = [];
});

afterEach(function () {
    foreach ($this->fixturePaths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('strips comments and docblocks without changing behaviour', function () {
    $path = apsConnectWriteObfuscatorFixture(<<<'PHP'
    <?php

    /**
     * A docblock that should not survive packing.
     */
    function apsObfCommentsExample(int $x): int
    {
        // an inline comment explaining the business logic
        $doubled = $x * 2; // trailing comment

        return $doubled;
    }
    PHP);
    $this->fixturePaths[] = $path;

    (new PhpSourceObfuscator)->obfuscateFile($path);

    $output = file_get_contents($path);

    expect($output)->not->toContain('docblock that should not survive');
    expect($output)->not->toContain('inline comment explaining');
    expect($output)->not->toContain('trailing comment');

    require $path;
    expect(apsObfCommentsExample(4))->toBe(8);
});

it('renames local variables while preserving behaviour', function () {
    $path = apsConnectWriteObfuscatorFixture(<<<'PHP'
    <?php

    function apsObfRenameExample(int $count): int
    {
        $doubledCount = $count * 2;
        $tripledCount = $doubledCount + $count;

        return $tripledCount;
    }
    PHP);
    $this->fixturePaths[] = $path;

    (new PhpSourceObfuscator)->obfuscateFile($path);

    $output = file_get_contents($path);

    expect($output)->not->toContain('doubledCount');
    expect($output)->not->toContain('tripledCount');
    expect($output)->toContain('$count'); // the parameter itself is never renamed

    require $path;
    expect(apsObfRenameExample(3))->toBe(9);
});

it('leaves a variable alone when it is also referenced by compact()', function () {
    $path = apsConnectWriteObfuscatorFixture(<<<'PHP'
    <?php

    function apsObfCompactExample(int $itemCount): array
    {
        $total = $itemCount * 3;

        return compact('total');
    }
    PHP);
    $this->fixturePaths[] = $path;

    (new PhpSourceObfuscator)->obfuscateFile($path);

    expect(file_get_contents($path))->toContain('$total');

    require $path;
    expect(apsObfCompactExample(2))->toBe(['total' => 6]);
});

it('leaves a global-declared variable alone at both the declaration site and the global statement', function () {
    $path = apsConnectWriteObfuscatorFixture(<<<'PHP'
    <?php

    $apsObfGlobalCounter = 0;

    function apsObfGlobalExample(): int
    {
        global $apsObfGlobalCounter;

        $apsObfGlobalCounter++;

        return $apsObfGlobalCounter;
    }
    PHP);
    $this->fixturePaths[] = $path;

    (new PhpSourceObfuscator)->obfuscateFile($path);

    expect(file_get_contents($path))->toContain('apsObfGlobalCounter');

    require $path;
    expect(apsObfGlobalExample())->toBe(1);
    expect(apsObfGlobalExample())->toBe(2);
});

it('skips renaming entirely for a scope that uses extract()', function () {
    $path = apsConnectWriteObfuscatorFixture(<<<'PHP'
    <?php

    function apsObfExtractExample(array $data): int
    {
        $bonus = 1;

        extract($data);

        return $shipped + $bonus;
    }
    PHP);
    $this->fixturePaths[] = $path;

    (new PhpSourceObfuscator)->obfuscateFile($path);

    expect(file_get_contents($path))->toContain('$bonus');

    require $path;
    expect(apsObfExtractExample(['shipped' => 10]))->toBe(11);
});

it('keeps a closure use() capture in sync between the outer scope and the closure body', function () {
    $path = apsConnectWriteObfuscatorFixture(<<<'PHP'
    <?php

    function apsObfClosureExample(int $base): int
    {
        $offset = 5;

        $addOffset = function (int $value) use ($offset): int {
            return $value + $offset;
        };

        return $addOffset($base);
    }
    PHP);
    $this->fixturePaths[] = $path;

    (new PhpSourceObfuscator)->obfuscateFile($path);

    require $path;
    expect(apsObfClosureExample(10))->toBe(15);
});

it('never renames a variable captured by a closure use(), on either side, to keep both in sync safely', function () {
    $path = apsConnectWriteObfuscatorFixture(<<<'PHP'
    <?php

    function apsObfClosureRenameExample(int $base): int
    {
        $multiplierValue = 3;

        $apply = function (int $value) use ($multiplierValue): int {
            return $value * $multiplierValue;
        };

        return $apply($base);
    }
    PHP);
    $this->fixturePaths[] = $path;

    (new PhpSourceObfuscator)->obfuscateFile($path);

    // Deliberately conservative: renaming a use()-captured variable would
    // require generating one shared replacement and applying it on both
    // the outer occurrence and every reference inside the closure — left
    // unrenamed instead rather than risk the two sides drifting apart.
    expect(file_get_contents($path))->toContain('multiplierValue');

    require $path;
    expect(apsObfClosureRenameExample(4))->toBe(12);
});

it('throws when the file cannot be parsed', function () {
    $path = apsConnectWriteObfuscatorFixture('<?php this is not valid php {{{');
    $this->fixturePaths[] = $path;

    (new PhpSourceObfuscator)->obfuscateFile($path);
})->throws(RuntimeException::class);
