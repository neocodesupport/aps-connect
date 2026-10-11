<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console\Commands\Concerns;

use Closure;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

/**
 * Shared by the aps-connect commands: they ask for a value only when it
 * wasn't already supplied via an option/argument, through Laravel Prompts,
 * and every prompt no-ops (returns null/the default) outside an interactive
 * terminal rather than blocking under `--no-interaction` or in a non-TTY
 * context. Laravel itself falls Prompts back to plain Symfony questions on
 * Windows and in tests (ConfiguresPrompts), which keeps `expectsQuestion()`
 * and friends working.
 */
trait PromptsMissingOptions
{
    /**
     * A required text prompt, re-asked until $validate returns true; null
     * outside an interactive terminal.
     *
     * @param  Closure(string): (bool|string)  $validate  true when valid, else the error to show
     */
    private function askUntilValid(string $question, Closure $validate, string $hint = ''): ?string
    {
        if (! $this->input->isInteractive()) {
            return null;
        }

        return text(
            label: $question,
            required: true,
            validate: static function (string $value) use ($validate): ?string {
                $result = $validate($value);

                return $result === true ? null : (is_string($result) ? $result : 'Valeur invalide.');
            },
            hint: $hint,
        );
    }

    /**
     * An optional text prompt: empty input keeps $default. Returns $default
     * outside an interactive terminal.
     */
    private function askWithDefault(string $question, string $default, string $hint = ''): string
    {
        if (! $this->input->isInteractive()) {
            return $default;
        }

        $answer = text(label: $question, default: $default, hint: $hint);

        return $answer !== '' ? $answer : $default;
    }

    /**
     * An optional text prompt with no default: null when left empty, or
     * outside an interactive terminal.
     */
    private function askOptional(string $question, string $hint = ''): ?string
    {
        if (! $this->input->isInteractive()) {
            return null;
        }

        $answer = trim(text(label: $question, hint: $hint));

        return $answer !== '' ? $answer : null;
    }

    private function secretIfInteractive(string $question, string $hint = ''): ?string
    {
        if (! $this->input->isInteractive()) {
            return null;
        }

        $answer = password(label: $question, hint: $hint);

        return $answer !== '' ? $answer : null;
    }

    /**
     * @param  list<string>  $choices
     */
    private function choiceString(string $question, array $choices, string $default): string
    {
        if (! $this->input->isInteractive()) {
            return $default;
        }

        $answer = select(label: $question, options: $choices, default: $default);

        return is_string($answer) ? $answer : $default;
    }

    /**
     * A yes/no prompt; $default outside an interactive terminal.
     */
    private function confirmIfInteractive(string $question, bool $default = false, string $hint = ''): bool
    {
        if (! $this->input->isInteractive()) {
            return $default;
        }

        return confirm(label: $question, default: $default, yes: 'Oui', no: 'Non', hint: $hint);
    }

    /**
     * Runs $callback behind a spinner (a network call, a build...).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withSpinner(string $message, Closure $callback): mixed
    {
        return spin($callback, $message);
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * `Command::option()` returns `array|bool|float|int|string|null` — every
     * string option on the commands using this trait is a plain flag, so
     * anything else (only reachable by a caller bypassing Artisan's own
     * parsing) is treated the same as "not provided" rather than coerced.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
