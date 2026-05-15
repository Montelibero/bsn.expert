<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\CurrentUser;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class WhoAreYouController
{
    public function __construct(
        private readonly Environment $Twig,
        private readonly CurrentUser $CurrentUser,
        private readonly Translator $Translator,
    ) {
    }

    public function WhoAreYou(): string
    {
        $Template = $this->Twig->load('who_are_you.twig');
        $return_to = $this->resolveReturnToFromRequest('/');
        $current_account_id = strtoupper(trim((string) ($_GET['current_account'] ?? '')));

        if ($current_account_id === '') {
            $current_account_id = $this->CurrentUser->getCurrentAccountId() ?? '';
        }

        return $Template->render([
            'return_to' => $return_to,
            'current_account_value' => $current_account_id,
            'current_account_error' => $this->resolveWhoAreYouError(),
        ]);
    }

    private function resolveWhoAreYouError(): ?string
    {
        if (($_GET['error'] ?? null) !== 'invalid_account_id') {
            return null;
        }

        return $this->Translator->trans('preferences.current_account.errors.invalid_account_id');
    }

    private function resolveReturnToFromRequest(string $fallback = '/'): string
    {
        foreach ([$_GET['return_to'] ?? null, $_POST['return_to'] ?? null, $_SERVER['HTTP_REFERER'] ?? null] as $candidate) {
            $return_to = $this->normalizeReturnTo($candidate, '');
            if ($return_to !== '') {
                return $return_to;
            }
        }

        return $this->normalizeReturnTo($fallback, '/');
    }

    private function normalizeReturnTo(?string $return_to, string $fallback = '/'): string
    {
        $return_to = LoginController::normalizeReturnTo($return_to, $fallback);
        if (preg_match('~^/(who_are_you|preferences)(?:[/?#]|$)~', $return_to)) {
            return LoginController::normalizeReturnTo($fallback, '/');
        }

        return $return_to;
    }
}
