<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentAccountOptions;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\ReturnTo;
use Pecee\SimpleRouter\SimpleRouter;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class WhoAreYouController
{
    public function __construct(
        private readonly Environment $Twig,
        private readonly CurrentUser $CurrentUser,
        private readonly CurrentAccountOptions $CurrentAccountOptions,
        private readonly Translator $Translator,
    ) {
    }

    public function WhoAreYou(): string
    {
        return $this->Twig->render('who_are_you.twig', $this->buildViewData());
    }

    public function Modal(): string
    {
        return $this->Twig->render('who_are_you_modal.twig', $this->buildViewData());
    }

    public function IgnoreOption(): ?string
    {
        $key = $_POST['ca_key'] ?? null;
        if (!$this->CurrentUser->isCurrentAccountSwitchKeyValid(is_string($key) ? $key : null)) {
            return $this->respondToIgnoreOption(['status' => 'error', 'message' => 'invalid_ca_key'], 403);
        }

        $account_id = strtoupper(trim((string) ($_POST['account_id'] ?? '')));
        if (!BSN::validateStellarAccountIdFormat($account_id)) {
            return $this->respondToIgnoreOption(['status' => 'error', 'message' => 'invalid_account_id'], 400);
        }

        if ($account_id === $this->CurrentUser->getAccountId()) {
            return $this->respondToIgnoreOption(['status' => 'error', 'message' => 'auth_account_cannot_be_ignored'], 400);
        }

        if (!$this->CurrentUser->ignoreCurrentAccountOption($account_id)) {
            return $this->respondToIgnoreOption(['status' => 'error', 'message' => 'ignore_failed'], 400);
        }

        return $this->respondToIgnoreOption(['status' => 'ok']);
    }

    private function buildViewData(): array
    {
        $return_to = $this->resolveReturnToFromRequest('/');
        $current_account_id = strtoupper(trim((string) ($_GET['current_account'] ?? '')));

        if ($current_account_id === '') {
            $current_account_id = $this->CurrentUser->getCurrentAccountId() ?? '';
        }

        return [
            'return_to' => $return_to,
            'current_account_value' => $current_account_id,
            'current_account_options' => $this->CurrentAccountOptions->all(),
            'ca_key' => $this->CurrentUser->getCurrentAccountSwitchKey(),
            'current_account_error' => $this->resolveWhoAreYouError(),
        ];
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
        return ReturnTo::getFromRequest($fallback, ['login', 'logout', 'who_are_you', 'preferences']);
    }

    private function respondToIgnoreOption(array $payload, int $status = 200): ?string
    {
        if ($this->wantsJson()) {
            SimpleRouter::response()->header('Content-Type', 'application/json; charset=utf-8');
            SimpleRouter::response()->httpCode($status);
            return json_encode($payload);
        }

        if ($status >= 400) {
            SimpleRouter::response()->httpCode($status);
            return $payload['message'] ?? 'error';
        }

        $return_to = ReturnTo::normalize(
            $_SERVER['HTTP_REFERER'] ?? $_POST['return_to'] ?? '/who_are_you',
            '/who_are_you'
        );
        SimpleRouter::response()->redirect($return_to, 302);
        return null;
    }

    private function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }
}
