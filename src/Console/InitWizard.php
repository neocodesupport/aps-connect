<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console;

use Closure;
use Laravel\Prompts\Exceptions\FormRevertedException;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\form;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

/**
 * The interactive questions of `aps-connect:init`, as one Laravel Prompts
 * form: Ctrl+U goes back one question, keeping every answer already given
 * (each question reopens with its previous answer as default). Questions
 * that don't apply (a tagline for a module...) are skipped both ways, and
 * any value already passed as an option skips its question entirely.
 *
 * Creating something ends on a recap the user confirms — the last chance to
 * go back and change an answer before anything is created on App Station.
 */
final class InitWizard
{
    public const array LICENSE_TYPES = ['free', 'freemium', 'paid', 'open_source', 'subscription'];

    public const int MAX_TEXT_LENGTH = 255;

    private const string LINK = 'Lier ce projet à un software/module App Station existant';

    private const string CREATE = 'Créer un nouveau software/module sur App Station';

    private const string BACK_HINT = 'Ctrl+U pour revenir à la question précédente.';

    /** @var array<string, list<array<string, mixed>>> */
    private array $lists = [];

    /**
     * @param  Closure(): list<array<string, mixed>>  $loadSoftwares
     * @param  Closure(): list<array<string, mixed>>  $loadPackages
     */
    public function __construct(
        private readonly Closure $loadSoftwares,
        private readonly Closure $loadPackages,
    ) {}

    /**
     * @param  array{type?: ?string, mode?: ?string, id?: ?int, parent_id?: ?int, name?: ?string, tagline?: ?string, license_type?: ?string, has_modules?: ?bool}  $presets  values given as options
     * @return array{type: string, mode: string, id: ?int, parent_id: ?int, name: ?string, tagline: ?string, license_type: ?string, has_modules: ?bool}|null null when the user declines the recap
     */
    public function run(array $presets, string $detectedName): ?array
    {
        $preset = static fn (string $key): mixed => $presets[$key] ?? null;

        $type = static fn (array $r): string => $preset('type') ?? $r['type'];
        $mode = static fn (array $r): string => $preset('mode') ?? $r['mode'];
        $creating = static fn (array $r): bool => $mode($r) === 'create';
        $creatingSoftware = static fn (array $r): bool => $creating($r) && $type($r) === 'software';
        // An empty list sends the user back to a question they can change
        // (link vs create...) — only when one was actually asked.
        $canGoBack = $preset('type') === null || $preset('mode') === null;

        $responses = form()
            ->addIf($preset('type') === null, fn (array $r, mixed $previous) => select(
                label: 'Ce projet est-il un software ou un module ?',
                options: ['software', 'module'],
                default: is_string($previous) ? $previous : 'software',
            ), name: 'type')
            ->addIf($preset('mode') === null, fn (array $r, mixed $previous) => select(
                label: 'Que souhaitez-vous faire ?',
                options: [self::LINK, self::CREATE],
                default: $previous === 'create' ? self::CREATE : self::LINK,
                hint: self::BACK_HINT,
            ) === self::CREATE ? 'create' : 'link', name: 'mode')
            ->addIf(fn (array $r) => ! $creating($r) && $preset('id') === null, fn (array $r, mixed $previous) => $type($r) === 'module'
                ? $this->pick('Module App Station à lier ?', $this->packages(), $previous, 'Aucun module trouvé sur votre compte : choisissez plutôt d\'en créer un.', $canGoBack)
                : $this->pick('Software App Station à lier ?', $this->softwares(), $previous, 'Aucun software trouvé sur votre compte : choisissez plutôt d\'en créer un.', $canGoBack), name: 'id')
            ->addIf(fn (array $r) => $creating($r) && $type($r) === 'module' && $preset('parent_id') === null, fn (array $r, mixed $previous) => $this->pick(
                'Software parent du module ?',
                array_values(array_filter($this->softwares(), static fn (array $software): bool => ($software['has_modules'] ?? false) === true)),
                $previous,
                'Aucun de vos softwares ne propose de modules. Activez-les sur le software parent — "php artisan aps-connect:update --has-modules" depuis son dépôt, ou « Propose des modules » dans App Station — puis relancez.',
                $canGoBack,
            ), name: 'parent_id')
            ->addIf(fn (array $r) => $creating($r) && $preset('name') === null, fn (array $r, mixed $previous) => text(
                label: 'Nom du '.$type($r),
                default: is_string($previous) ? $previous : $detectedName,
                required: 'Le nom est obligatoire.',
                validate: static fn (string $value): ?string => mb_strlen($value) > self::MAX_TEXT_LENGTH ? '255 caractères maximum.' : null,
                hint: self::BACK_HINT,
            ), name: 'name')
            ->addIf(fn (array $r) => $creatingSoftware($r) && $preset('tagline') === null, fn (array $r, mixed $previous) => text(
                label: 'Tagline',
                default: is_string($previous) ? $previous : '',
                validate: static fn (string $value): ?string => mb_strlen($value) > self::MAX_TEXT_LENGTH ? '255 caractères maximum.' : null,
                hint: 'Optionnelle, 255 caractères max — laissez vide pour passer. '.self::BACK_HINT,
            ), name: 'tagline')
            ->addIf(fn (array $r) => $creatingSoftware($r) && $preset('license_type') === null, fn (array $r, mixed $previous) => select(
                label: 'Type de licence',
                options: self::LICENSE_TYPES,
                default: is_string($previous) ? $previous : 'freemium',
                hint: self::BACK_HINT,
            ), name: 'license_type')
            ->addIf(fn (array $r) => $creatingSoftware($r) && $preset('has_modules') === null, fn (array $r, mixed $previous) => confirm(
                label: 'Ce software propose-t-il des modules ?',
                default: $previous === true,
                yes: 'Oui',
                no: 'Non',
                hint: 'Optionnels, vendus ou activés séparément — requis pour créer des modules rattachés. '.self::BACK_HINT,
            ), name: 'has_modules')
            ->addIf($creating, fn (array $r) => confirm(
                label: $this->recap($r, $presets, $type($r)),
                default: true,
                yes: 'Créer',
                no: 'Annuler',
                hint: 'Ctrl+U pour revenir en arrière et modifier une réponse.',
            ), name: 'confirmed')
            ->submit();

        $answers = [
            'type' => $preset('type') ?? $responses['type'],
            'mode' => $preset('mode') ?? $responses['mode'],
            'id' => $preset('id') ?? $responses['id'] ?? null,
            'parent_id' => $preset('parent_id') ?? $responses['parent_id'] ?? null,
            'name' => $preset('name') ?? $responses['name'] ?? null,
            'tagline' => $preset('tagline') ?? (($responses['tagline'] ?? '') !== '' ? $responses['tagline'] : null),
            'license_type' => $preset('license_type') ?? $responses['license_type'] ?? null,
            'has_modules' => $preset('has_modules') ?? $responses['has_modules'] ?? null,
        ];

        return $answers['mode'] === 'create' && ($responses['confirmed'] ?? false) !== true ? null : $answers;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function pick(string $label, array $items, mixed $previous, string $emptyMessage, bool $canGoBack): int
    {
        if ($items === []) {
            if (! $canGoBack) {
                throw new RuntimeException($emptyMessage);
            }

            error($emptyMessage);

            throw new FormRevertedException;
        }

        $choices = [];

        foreach ($items as $item) {
            $parent = is_array($item['software'] ?? null) && is_string($item['software']['name'] ?? null) ? "parent : {$item['software']['name']}, " : '';
            $choices['#'.$item['id'].' '.$item['name']." ({$parent}{$item['status']})"] = (int) $item['id'];
        }

        $labels = array_keys($choices);
        $default = array_search($previous, $choices, true);

        $label = select(label: $label, options: $labels, default: is_string($default) ? $default : $labels[0], scroll: 10, hint: self::BACK_HINT);

        return $choices[$label] ?? throw new RuntimeException("Choix inattendu : {$label}");
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, mixed>  $presets
     */
    private function recap(array $r, array $presets, string $type): string
    {
        $value = static fn (string $key): mixed => $presets[$key] ?? $r[$key] ?? null;
        $name = $value('name');

        if ($type === 'module') {
            $parentId = $value('parent_id');
            $parent = array_values(array_filter($this->softwares(), static fn (array $s): bool => ($s['id'] ?? null) === $parentId))[0]['name'] ?? "#{$parentId}";

            return "Créer le module « {$name} » sous « {$parent} » ?";
        }

        $tagline = $value('tagline');
        $details = array_filter([
            is_string($tagline) && $tagline !== '' ? "« {$tagline} »" : null,
            'licence '.$value('license_type'),
            $value('has_modules') === true ? 'avec modules' : 'sans modules',
        ]);

        return "Créer le software « {$name} » (".implode(', ', $details).') ?';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function softwares(): array
    {
        return $this->lists['softwares'] ??= spin($this->loadSoftwares, 'Chargement de vos softwares App Station...');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function packages(): array
    {
        return $this->lists['packages'] ??= spin($this->loadPackages, 'Chargement de vos modules App Station...');
    }
}
