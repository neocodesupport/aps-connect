<?php

declare(strict_types=1);

namespace Neocode\ApsConnect\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;
use Neocode\ApsConnect\Console\Commands\Concerns\LinksAppStationProject;
use Neocode\ApsConnect\Console\Commands\Concerns\PromptsMissingOptions;
use Neocode\ApsConnect\Exceptions\MissingCredentialsException;
use Neocode\ApsConnect\Exceptions\PublisherRequestException;
use Neocode\ApsConnect\Exceptions\PublisherValidationException;
use Neocode\ApsConnect\Http\PublisherClient;
use Neocode\ApsConnect\Support\ApsCliSession;
use Neocode\ApsConnect\Support\ProjectConfigReader;
use RuntimeException;

/**
 * PHP counterpart of `aps update` (aps-cli/src/commands/update.ts): partial
 * update of the linked software's/module's App Station listing — only the
 * fields passed as options change. Limits are App Station's own
 * (PublisherResourceRules + the publisher PATCH endpoints), checked here
 * first so a typo fails with a clear message instead of a 422.
 */
class ApsConnectUpdateCommand extends Command
{
    use LinksAppStationProject;
    use PromptsMissingOptions;

    private const array SOFTWARE_ONLY_OPTIONS = ['tagline', 'price-per-day', 'lifetime-price', 'trial-period-days', 'disable-trial'];

    /**
     * The command signature.
     */
    protected $signature = 'aps-connect:update
        {--name= : Name, 2 to 120 characters (software or module)}
        {--description= : Description, 20,000 characters max (software or module)}
        {--tagline= : Short description, 180 characters max (software only)}
        {--price-per-day= : Price per day in XOF (software only)}
        {--lifetime-price= : Lifetime price in XOF (software only)}
        {--trial-period-days= : Enable the free trial, 1 to 365 days (software only)}
        {--disable-trial : Disable the free trial (software only)}';

    /**
     * The command description.
     */
    protected $description = 'Update the linked software\'s/module\'s App Station listing (name, prices, trial...) — like "aps update".';

    /**
     * Execute the console command.
     */
    public function handle(ApsCliSession $session, ProjectConfigReader $reader, HttpFactory $http): int
    {
        try {
            $identity = $reader->projectIdentity();
        } catch (MissingCredentialsException) {
            $this->components->error('appstation.conf.json introuvable ou incomplet. Exécutez "php artisan aps-connect:init".');

            return self::FAILURE;
        }

        try {
            $changes = $this->changes($identity->isModule());
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $credentials = $this->resolveSession($session);

        if ($credentials === null) {
            return self::FAILURE;
        }

        $client = new PublisherClient($credentials, $http);

        try {
            $updated = $identity->isModule()
                ? $client->updatePackage($identity->id, $changes)
                : $client->updateSoftware($identity->id, $changes);
        } catch (PublisherValidationException $e) {
            $this->components->error('Validation refusée par App Station :');

            foreach ($e->errors as $field => $messages) {
                foreach ($messages as $message) {
                    $this->line("  - {$field}: {$message}");
                }
            }

            return self::FAILURE;
        } catch (PublisherRequestException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $name = is_string($updated['name'] ?? null) ? $updated['name'] : $identity->name;
        $this->components->info(($identity->isModule() ? 'Module' : 'Software')." mis à jour : {$name}.");

        return self::SUCCESS;
    }

    /**
     * The PATCH body, from the options actually passed.
     *
     * @return array<string, bool|int|string>
     *
     * @throws RuntimeException on an invalid or contradictory option
     */
    private function changes(bool $isModule): array
    {
        if ($isModule) {
            $unsupported = array_filter(self::SOFTWARE_ONLY_OPTIONS, fn (string $option): bool => $this->option($option) !== null && $this->option($option) !== false);

            if ($unsupported !== []) {
                throw new RuntimeException(implode(', ', array_map(static fn (string $o): string => "--{$o}", $unsupported)).' non disponible pour un module (tagline, prix et essai sont des champs software uniquement).');
            }
        }

        $trialDays = $this->stringOption('trial-period-days');

        if ($trialDays !== null && $this->option('disable-trial')) {
            throw new RuntimeException('--trial-period-days et --disable-trial sont contradictoires : choisissez-en un seul.');
        }

        $changes = [];

        if (($name = $this->stringOption('name')) !== null) {
            $length = mb_strlen($name);

            if ($length < 2 || $length > 120) {
                throw new RuntimeException('--name doit faire entre 2 et 120 caractères.');
            }

            $changes['name'] = $name;
        }

        if (($description = $this->stringOption('description')) !== null) {
            if (mb_strlen($description) > 20000) {
                throw new RuntimeException('--description ne doit pas dépasser 20 000 caractères.');
            }

            $changes['description'] = $description;
        }

        if (! $isModule) {
            if (($tagline = $this->stringOption('tagline')) !== null) {
                if (mb_strlen($tagline) > 180) {
                    throw new RuntimeException('--tagline ne doit pas dépasser 180 caractères.');
                }

                $changes['tagline'] = $tagline;
            }

            if (($price = $this->stringOption('price-per-day')) !== null) {
                $changes['price_per_day_xof'] = $this->integerInRange($price, 0, null, '--price-per-day');
            }

            if (($price = $this->stringOption('lifetime-price')) !== null) {
                $changes['lifetime_price_xof'] = $this->integerInRange($price, 0, null, '--lifetime-price');
            }

            if ($trialDays !== null) {
                $changes['has_trial_mode'] = true;
                $changes['trial_period_days'] = $this->integerInRange($trialDays, 1, 365, '--trial-period-days');
            }

            if ($this->option('disable-trial')) {
                $changes['has_trial_mode'] = false;
            }
        }

        if ($changes === []) {
            throw new RuntimeException('Aucun champ à mettre à jour. Utilisez --name, --description'.($isModule ? '' : ', --tagline, --price-per-day, --lifetime-price, --trial-period-days, --disable-trial').'.');
        }

        return $changes;
    }

    private function integerInRange(string $value, int $min, ?int $max, string $option): int
    {
        if (! ctype_digit($value) || (int) $value < $min || ($max !== null && (int) $value > $max)) {
            throw new RuntimeException($max === null
                ? "{$option} doit être un entier positif ou nul, reçu : {$value}"
                : "{$option} doit être un entier entre {$min} et {$max}, reçu : {$value}");
        }

        return (int) $value;
    }
}
