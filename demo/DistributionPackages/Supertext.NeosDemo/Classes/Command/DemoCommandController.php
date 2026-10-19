<?php

declare(strict_types=1);

namespace Supertext\NeosDemo\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\UserService;
use Supertext\NeosTranslation\Service\LanguageMappingStore;

/**
 * Supertext demo setup
 */
class DemoCommandController extends CommandController
{
    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    #[Flow\Inject]
    protected LanguageMappingStore $languageMappingStore;

    /**
     * Choose the demo's Supertext languages under Administration → Supertext if nobody has yet
     *
     * Swiss variants with the formal address for German, French and Italian. Once anything is
     * stored (e.g. changed in the module), it is left as it is.
     */
    public function ensureLanguagesCommand(): void
    {
        if ($this->languageMappingStore->get() !== []) {
            $this->outputLine('Supertext languages: already chosen, left as they are.');
            return;
        }
        $this->languageMappingStore->save([
            'de' => ['code' => 'de-CH', 'politeness' => 'more'],
            'fr' => ['code' => 'fr-CH', 'politeness' => 'more'],
            'it' => ['code' => 'it-CH', 'politeness' => 'more'],
        ]);
        $this->outputLine('Supertext languages: de → de-CH, fr → fr-CH, it → it-CH (formal).');
    }

    /**
     * Create the demo accounts from DEMO_ADMIN_* / DEMO_EDITOR_* if they don't exist
     *
     * The e-mail address is the Neos username. Existing accounts are never changed;
     * passwords are never printed.
     */
    public function ensureAccountsCommand(): void
    {
        $this->ensure('DEMO_ADMIN', ['Neos.Neos:Administrator'], 'Demo', 'Administrator');
        $this->ensure('DEMO_EDITOR', ['Neos.Neos:Editor'], 'Demo', 'Editor');
        $this->persistenceManager->persistAll();
    }

    /** @param list<string> $roles */
    private function ensure(string $prefix, array $roles, string $firstName, string $lastName): void
    {
        $email = trim((string)getenv($prefix . '_EMAIL'));
        $password = (string)getenv($prefix . '_PASSWORD');
        if ($email === '' || $password === '') {
            $this->outputLine('[demo] %1$s_EMAIL / %1$s_PASSWORD not set, skipping that account', [$prefix]);
            return;
        }
        if ($this->userService->getUser($email) !== null) {
            $this->outputLine('[demo] %s account already exists, leaving it unchanged', [$prefix]);
            return;
        }
        try {
            $this->userService->createUser($email, $password, $firstName, $lastName, $roles);
            $this->outputLine('[demo] created %s account (%s)', [$prefix, implode(', ', $roles)]);
        } catch (\Throwable $e) {
            // The message names the rule that failed; it never contains the password.
            $this->outputLine('[demo] WARNING: %s account not created: %s', [$prefix, $e->getMessage()]);
        }
    }
}
