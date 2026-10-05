<?php

declare(strict_types=1);

namespace Supertext\NeosDemo\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\UserService;

/**
 * Supertext demo setup
 */
class DemoCommandController extends CommandController
{
    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

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
