<?php

declare(strict_types=1);

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Neocode\ApsConnect\Console\InitWizard;

beforeEach(function () {
    // Artisan tests switch Prompts to its plain-question fallback for the
    // rest of the process; these drive the real prompts with fake keys.
    (new ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, false);

    $this->softwaresLoaded = 0;
    $this->wizard = new InitWizard(
        function () {
            $this->softwaresLoaded++;

            return $this->softwares ?? [['id' => 7, 'name' => 'My App', 'status' => 'published', 'has_modules' => true]];
        },
        fn () => [],
    );
});

it('goes back with Ctrl+U without losing the answers already given', function () {
    Prompt::fake([
        Key::ENTER,                      // software
        Key::DOWN, Key::ENTER,           // create
        Key::ENTER,                      // name: keeps the detected "my-app"
        'S', 'h', 'o', 'p', Key::ENTER,  // tagline
        Key::ENTER,                      // licence: freemium
        Key::ENTER,                      // modules: no
        Key::CTRL_U,                     // recap → back to "modules"
        Key::CTRL_U,                     // → back to "licence", still on freemium
        Key::DOWN, Key::ENTER,           // licence: paid
        Key::ENTER,                      // modules: still no
        Key::ENTER,                      // recap: create
    ]);

    expect($this->wizard->run([], 'my-app'))->toBe([
        'type' => 'software',
        'mode' => 'create',
        'id' => null,
        'parent_id' => null,
        'name' => 'my-app',
        'tagline' => 'Shop',
        'license_type' => 'paid',
        'has_modules' => false,
    ]);
})->skipOnWindows();

it('returns null when the recap is declined', function () {
    Prompt::fake([
        Key::ENTER, Key::DOWN, Key::ENTER,   // software, create
        Key::ENTER, Key::ENTER, Key::ENTER,  // name, tagline, licence
        Key::ENTER,                          // modules
        Key::RIGHT, Key::ENTER,              // recap: "Annuler"
    ]);

    expect($this->wizard->run([], 'my-app'))->toBeNull();
})->skipOnWindows();

it('skips the questions options already answer', function () {
    Prompt::fake([
        Key::ENTER, // parent: My App (the only software offering modules)
        Key::ENTER, // recap
    ]);

    expect($this->wizard->run(['type' => 'module', 'mode' => 'create', 'name' => 'Invoices'], 'ignored'))->toMatchArray([
        'type' => 'module',
        'mode' => 'create',
        'parent_id' => 7,
        'name' => 'Invoices',
        'tagline' => null,
    ]);
})->skipOnWindows();

it('sends the user back to "link or create" when there is nothing to link', function () {
    $this->softwares = [];

    Prompt::fake([
        Key::ENTER,               // software
        Key::ENTER,               // link → empty list, back to this question
        Key::DOWN, Key::ENTER,    // create instead
        Key::ENTER, Key::ENTER,   // name, tagline
        Key::ENTER, Key::ENTER,   // licence, modules
        Key::ENTER,               // recap
    ]);

    expect($this->wizard->run([], 'my-app'))->toMatchArray(['mode' => 'create', 'name' => 'my-app'])
        ->and($this->softwaresLoaded)->toBe(1);

    Prompt::assertOutputContains('Aucun software trouvé sur votre compte');
})->skipOnWindows();
