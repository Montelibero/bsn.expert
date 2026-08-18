<?php

declare(strict_types=1);

namespace Montelibero\BSN;

use Montelibero\BSN\Controllers\TokensController;

final class TokenLabelFormatter
{
    public function __construct(
        private readonly TokensController $TokensController,
        private readonly CurrentContacts $CurrentContacts,
    ) {
    }

    public function format(string $code, ?string $issuer = null): string
    {
        if ($issuer === null || $issuer === '') {
            return $code;
        }

        if ($this->TokensController->getKnownToken($code . '-' . $issuer) !== null) {
            return $code;
        }

        $label = $code . '-' . Account::fromId($issuer)->getShortId();
        if ($contact_name = $this->CurrentContacts->getContactName($issuer)) {
            $label .= ' [📒 ' . $contact_name . ']';
        }

        return $label;
    }

    public function formatToken(array $token): string
    {
        $code = (string) ($token['code'] ?? $token['label'] ?? $token['key'] ?? '');
        $issuer = $token['issuer'] ?? $token['asset_issuer'] ?? null;

        return $this->format($code, is_string($issuer) ? $issuer : null);
    }
}
