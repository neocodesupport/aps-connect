<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console\Commands\Concerns;

use Closure;

/**
 * Shared by ApsConnectReleaseCommand and ApsConnectInstallCommand: both ask
 * for a value only when it wasn't already supplied via an option/argument,
 * and both need every prompt to no-op (return null/the default) outside an
 * interactive terminal rather than blocking on Artisan's mocked IO under
 * `--no-interaction` or in a non-TTY context.
 */
trait PromptsMissingOptions
{
    /**
     * Prompts in a loop until $validate returns true, or immediately
     * returns null outside an interactive terminal (Artisan's own
     * ask()/choice() already do this for a single prompt — this adds the
     * same guard around a *loop*, which would otherwise spin forever
     * re-asking a question that ask() keeps silently answering with null).
     */
    private function askUntilValid(string $question, Closure $validate): ?string
    {
        if (! $this->input->isInteractive()) {
            return null;
        }

        while (true) {
            $answer = $this->ask($question);

            if (! is_string($answer) || $answer === '') {
                continue;
            }

            $result = $validate($answer);

            if ($result === true) {
                return $answer;
            }

            $this->components->error(is_string($result) ? $result : 'Valeur invalide.');
        }
    }

    /**
     * A plain "ask once, empty input accepts the default" prompt — unlike
     * askUntilValid() above, this never loops: it's for fields with a
     * genuinely fine fallback (APP_URL, DB_HOST, ...), not ones where an
     * empty/invalid answer needs to be re-asked.
     */
    private function askWithDefault(string $question, string $default): string
    {
        if (! $this->input->isInteractive()) {
            return $default;
        }

        $answer = $this->ask($question, $default);

        return is_string($answer) && $answer !== '' ? $answer : $default;
    }

    private function secretIfInteractive(string $question): ?string
    {
        if (! $this->input->isInteractive()) {
            return null;
        }

        $answer = $this->secret($question);

        return is_string($answer) && $answer !== '' ? $answer : null;
    }

    /**
     * `Command::choice()` returns `array|string` (an array only when
     * `$multiple` is true, which neither command using this trait ever
     * sets) — narrows it back to the single string it always actually is.
     *
     * @param  list<string>  $choices
     */
    private function choiceString(string $question, array $choices, string $default): string
    {
        if (! $this->input->isInteractive()) {
            return $default;
        }

        $answer = $this->choice($question, $choices, $default);

        return is_string($answer) ? $answer : $default;
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
