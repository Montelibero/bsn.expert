<?php

namespace Montelibero\BSN;

class CrowdConfig
{
    public const MTLCROWD_CODE = 'MTLCrowd';
    public const MTLCROWD_ISSUER = 'GDRXBG5GVIUJWTAJDQE536JC5MDT5AH3MMCZIJCEGVAT2GEM2TMCROWD';

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
            'code' => self::MTLCROWD_CODE,
            'issuer' => self::MTLCROWD_ISSUER,
        ];
    }
}
