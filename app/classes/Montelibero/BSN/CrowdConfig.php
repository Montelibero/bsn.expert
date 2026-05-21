<?php

namespace Montelibero\BSN;

class CrowdConfig
{
    private const DEFAULT_CROWD_TOKEN_CODE = 'MTLCrowd';
    private const DEFAULT_CROWD_TOKEN_ISSUER = 'GDRXBG5GVIUJWTAJDQE536JC5MDT5AH3MMCZIJCEGVAT2GEM2TMCROWD';

    public function issuer(): ?string
    {
        $issuer = trim((string) ($_ENV['CROWD_STELLAR_ACCOUNT_ID'] ?? ''));
        return $issuer !== '' ? $issuer : null;
    }

    public function pinataJwt(): ?string
    {
        $jwt = trim((string) ($_ENV['PINATA_API_JWT'] ?? ''));
        return $jwt !== '' ? $jwt : null;
    }

    public function pinataApiKey(): ?string
    {
        $key = trim((string) ($_ENV['PINATA_API_KEY'] ?? ''));
        return $key !== '' ? $key : null;
    }

    public function pinataApiSecret(): ?string
    {
        $secret = trim((string) ($_ENV['PINATA_API_SECRET'] ?? ''));
        return $secret !== '' ? $secret : null;
    }

    public function pinataCrowdGroupId(): ?string
    {
        $group_id = trim((string) ($_ENV['PINATA_CROWD_GROUP_ID'] ?? ''));
        return $group_id !== '' ? $group_id : null;
    }

    public function mtlCrowdToken(): array
    {
        return [
            'code' => $this->crowdTokenCode(),
            'issuer' => $this->crowdTokenIssuer(),
        ];
    }

    public function crowdTokenCode(): string
    {
        $code = trim((string) ($_ENV['CROWD_TOKEN_CODE'] ?? ''));
        return $code !== '' ? $code : self::DEFAULT_CROWD_TOKEN_CODE;
    }

    public function crowdTokenIssuer(): string
    {
        $issuer = trim((string) ($_ENV['CROWD_TOKEN_ISSUER'] ?? ''));
        return $issuer !== '' ? $issuer : self::DEFAULT_CROWD_TOKEN_ISSUER;
    }
}
