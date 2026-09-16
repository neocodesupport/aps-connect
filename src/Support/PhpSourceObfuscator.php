<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Support;

use PhpParser\Error as PhpParserError;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;

/**
 * See ReleaseArchiveBuilder for wiring and the project's "source protection"
 * decision for the full reasoning. Summary: this is a deterrent against
 * casual browsing/editing of a self-hosted PHP release, not real security —
 * ionCube/Zend Guard-grade protection was explicitly ruled out (needs a
 * loader extension most customer-controlled hosting doesn't have).
 *
 * Two behaviour-preserving transforms only:
 *
 * 1. Parse -> pretty-print, which always drops every comment/docblock (the
 *    pretty printer never re-emits original comments) without touching
 *    behaviour at all.
 * 2. Rename local variables where provably safe: PHP scopes a variable to
 *    its enclosing function/method/closure body, unreachable from outside
 *    by name — except `compact('name')`, `global $name;`, a `use (&$x)`
 *    closure capture, and dynamic variable-variables (`$$x`)/`extract()`,
 *    which DO reach across that boundary by string/reference. Those are
 *    detected and left alone (or the whole scope skipped, for
 *    extract()/variable-variables) rather than guessed at.
 *
 * Class/method/property/namespace names are never touched — Laravel
 * resolves those via reflection/magic strings throughout (the container,
 * Eloquent, route model binding, compact()+view() data, job/listener
 * `handle()` conventions, named arguments) and a naive rename risks
 * silently breaking a customer's production app.
 */
final class PhpSourceObfuscator
{
    private const array SUPERGLOBALS = [
        'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV',
    ];

    /**
     * Every name ever passed to `global $x;` anywhere in the current file,
     * regardless of which scope declares it — reset per obfuscateFile()
     * call. `global $x;` binds to the true top-level file scope no matter
     * how deeply nested the function declaring it is, so this can't be
     * scoped like compact()/closure use() are: the top-level assignment a
     * `global` statement refers to must stay unrenamed too, even though the
     * top-level scope's own collector never sees inside the function body
     * that declares it (traversal stops at each nested Function_/
     * ClassMethod/Closure boundary — see obfuscateScope()).
     *
     * @var list<string>
     */
    private array $fileWideGlobalNames = [];

    public function obfuscateFile(string $path): void
    {
        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException("Unable to read {$path} while obfuscating the release.");
        }

        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $stmts = $parser->parse($source);
        } catch (PhpParserError $e) {
            throw new RuntimeException("Unable to parse {$path} while obfuscating the release: {$e->getMessage()}");
        }

        if ($stmts === null) {
            throw new RuntimeException("Unable to parse {$path} while obfuscating the release.");
        }

        $this->fileWideGlobalNames = $this->collectGlobalNames($stmts);

        $this->obfuscateScope($stmts);
        $this->stripComments($stmts);

        $printed = (new Standard)->prettyPrintFile($stmts);

        if (file_put_contents($path, $printed) === false) {
            throw new RuntimeException("Unable to write {$path} while obfuscating the release.");
        }
    }

    /**
     * Bounded to one function/method/closure body (or the file's top-level
     * statements) at a time: collects everything needed to decide a safe
     * rename map for *this* scope only, applies it, then recurses into each
     * nested scope it found — which gets its own, independent, map.
     *
     * @param  array<Stmt>  $stmts
     * @param  list<string>  $initiallyExcludedNames  Names that must stay
     *                                                stable in this scope regardless of use count — the scope's own
     *                                                parameters and any closure `use()` captures, collected by the
     *                                                caller from the scope-root node (not part of $stmts itself).
     */
    private function obfuscateScope(array $stmts, array $initiallyExcludedNames = []): void
    {
        $collector = new class extends NodeVisitorAbstract
        {
            /** @var list<string> */
            public array $variableNames = [];

            /** @var list<string> */
            public array $protectedNames = [];

            /** @var list<string> */
            public array $compactNames = [];

            public bool $hasDynamicVariables = false;

            /** @var list<Node> */
            public array $nestedScopes = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Function_ || $node instanceof ClassMethod) {
                    $this->nestedScopes[] = $node;

                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Closure) {
                    // `use (&$x)`/`use ($x)` names must stay in sync between
                    // this (outer) scope and the closure's own — protected
                    // here, and again when the closure's own scope is
                    // processed via PhpSourceObfuscator::scopeRootNames().
                    foreach ($node->uses as $use) {
                        if (is_string($use->var->name)) {
                            $this->protectedNames[] = $use->var->name;
                        }
                    }

                    $this->nestedScopes[] = $node;

                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof ArrowFunction) {
                    // Arrow functions implicitly capture the enclosing
                    // scope by name and aren't a rename boundary — their own
                    // parameters, however, shadow it and must stay put.
                    foreach ($node->getParams() as $param) {
                        if ($param->var instanceof Variable && is_string($param->var->name)) {
                            $this->protectedNames[] = $param->var->name;
                        }
                    }

                    return null;
                }

                if ($node instanceof Variable) {
                    // A non-string $name means `$$x`/`${expr}` — dynamic
                    // variable-variable syntax nikic/php-parser represents
                    // on this same node class rather than a separate one.
                    if (is_string($node->name)) {
                        $this->variableNames[] = $node->name;
                    } else {
                        $this->hasDynamicVariables = true;
                    }

                    return null;
                }

                if ($node instanceof Global_) {
                    foreach ($node->vars as $var) {
                        if ($var instanceof Variable && is_string($var->name)) {
                            $this->protectedNames[] = $var->name;
                        }
                    }

                    return null;
                }

                if ($node instanceof FuncCall && $node->name instanceof Name) {
                    $functionName = $node->name->toString();

                    if ($functionName === 'extract') {
                        $this->hasDynamicVariables = true;
                    }

                    if ($functionName === 'compact') {
                        foreach ($node->args as $arg) {
                            if ($arg instanceof Arg && $arg->value instanceof String_) {
                                $this->compactNames[] = $arg->value->value;
                            }
                        }
                    }

                    return null;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor($collector);
        $traverser->traverse($stmts);

        if (! $collector->hasDynamicVariables) {
            $excluded = array_values(array_unique([
                ...self::SUPERGLOBALS,
                'this',
                ...$initiallyExcludedNames,
                ...$this->fileWideGlobalNames,
                ...$collector->protectedNames,
                ...$collector->compactNames,
            ]));

            $renameMap = $this->buildRenameMap($collector->variableNames, $excluded);

            if ($renameMap !== []) {
                $this->applyRenameMap($stmts, $renameMap);
            }
        }

        foreach ($collector->nestedScopes as $nestedScope) {
            $this->obfuscateNestedScope($nestedScope);
        }
    }

    /**
     * @param  array<Stmt>  $stmts
     * @param  array<string, string>  $renameMap
     */
    private function applyRenameMap(array $stmts, array $renameMap): void
    {
        $renamer = new class($renameMap) extends NodeVisitorAbstract
        {
            /**
             * @param  array<string, string>  $renameMap
             */
            public function __construct(private readonly array $renameMap) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Function_ || $node instanceof ClassMethod || $node instanceof Closure) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Variable && is_string($node->name) && isset($this->renameMap[$node->name])) {
                    $node->name = $this->renameMap[$node->name];
                }

                return null;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor($renamer);
        $traverser->traverse($stmts);
    }

    private function obfuscateNestedScope(Node $scopeNode): void
    {
        $stmts = match (true) {
            $scopeNode instanceof Function_, $scopeNode instanceof ClassMethod, $scopeNode instanceof Closure => $scopeNode->stmts,
            default => null,
        };

        // An abstract method / interface method declaration has no body.
        if ($stmts === null) {
            return;
        }

        $this->obfuscateScope($stmts, $this->scopeRootNames($scopeNode));
    }

    /**
     * @return list<string>
     */
    private function scopeRootNames(Node $scopeNode): array
    {
        $names = [];

        if ($scopeNode instanceof Function_ || $scopeNode instanceof ClassMethod || $scopeNode instanceof Closure) {
            foreach ($scopeNode->getParams() as $param) {
                $this->collectParamName($param, $names);
            }
        }

        if ($scopeNode instanceof Closure) {
            foreach ($scopeNode->uses as $use) {
                if (is_string($use->var->name)) {
                    $names[] = $use->var->name;
                }
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $names
     */
    private function collectParamName(Param $param, array &$names): void
    {
        if ($param->var instanceof Variable && is_string($param->var->name)) {
            $names[] = $param->var->name;
        }
    }

    /**
     * The pretty printer re-emits a node's original leading comments/
     * docblocks by default (it's how tools built on this library preserve
     * them) — they have to be explicitly cleared to actually disappear from
     * the output, parsing+reprinting alone isn't enough.
     *
     * @param  array<Stmt>  $stmts
     */
    private function stripComments(array $stmts): void
    {
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class extends NodeVisitorAbstract
        {
            public function enterNode(Node $node): ?int
            {
                $node->setAttribute('comments', []);

                return null;
            }
        });
        $traverser->traverse($stmts);
    }

    /**
     * @param  array<Stmt>  $stmts
     * @return list<string>
     */
    private function collectGlobalNames(array $stmts): array
    {
        $names = [];

        foreach ((new NodeFinder)->findInstanceOf($stmts, Global_::class) as $global) {
            foreach ($global->vars as $var) {
                if ($var instanceof Variable && is_string($var->name)) {
                    $names[] = $var->name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $variableNames
     * @param  list<string>  $excluded
     * @return array<string, string>
     */
    private function buildRenameMap(array $variableNames, array $excluded): array
    {
        $excluded = array_flip($excluded);
        $candidates = array_unique(array_diff($variableNames, array_keys($excluded)));

        $map = [];
        $index = 0;

        foreach ($candidates as $name) {
            do {
                $replacement = '__o'.$index;
                $index++;
            } while (isset($excluded[$replacement]));

            $map[$name] = $replacement;
        }

        return $map;
    }
}
