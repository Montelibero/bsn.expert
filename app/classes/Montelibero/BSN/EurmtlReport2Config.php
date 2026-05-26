<?php

namespace Montelibero\BSN;

class EurmtlReport2Config
{
    public const ISSUER = 'GACKTN5DAZGWXRWB2WLM6OPBDHAMT6SJNGLJZPQMEZBUR4JUGBX2UK7V';
    public const MARKET_MAKER = 'GBSNN2SPYZB2A5RPDTO3BLX4TP5KNYI7UMUABUS3TYWWEWAAM2D7CMMW';
    public const MARKET_MAKER_POOL_ACCOUNT = 'GCWJOBIPJQRZLFGQ5RQKE4J3H2QXHAOHCFVDM3FH37APAM3QXQR7POOL';
    public const MARKET_MAKER_ASSET_ACCOUNT = 'GDLTH4KKMA4R2JGKA7XKI5DLHJBUT42D5RHVK6SS6YHZZLHVLCWJAYXI';
    public const BTPOOL = 'GBE7CNPKTQLZKIZRPKJ7X26NEVGXINR5NSQJONI72MNHQCMNSEBTPOOL';
    public const MARKET_MAKER_ACCOUNTS = [
        self::MARKET_MAKER,
        self::MARKET_MAKER_POOL_ACCOUNT,
        self::MARKET_MAKER_ASSET_ACCOUNT,
    ];
    public const EURMTL_CODE = 'EURMTL';
    public const EURDEBT_CODE = 'EURDEBT';

    public function snapshot(): array
    {
        return [
            'issuer' => self::ISSUER,
            'market_maker' => self::MARKET_MAKER,
            'assets' => [
                self::EURMTL_CODE => self::ISSUER,
                self::EURDEBT_CODE => self::ISSUER,
            ],
            'market_maker_accounts' => self::MARKET_MAKER_ACCOUNTS,
            'btpool_account' => self::BTPOOL,
            'issuer_accounts' => [
                self::ISSUER,
            ],
        ];
    }
}
