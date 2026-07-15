<?php

use DI\Container;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use MongoDB\Driver\WriteConcern;
use Montelibero\BSN\AccountsManager;
use Montelibero\BSN\ApiKeysManager;
use Montelibero\BSN\ApplicationContext;
use Montelibero\BSN\AssetVersions;
use Montelibero\BSN\BSN;
use Montelibero\BSN\ContactsManager;
use Montelibero\BSN\Controllers\CrowdController;
use Montelibero\BSN\CurrentContacts;
use Montelibero\BSN\Controllers\AdminController;
use Montelibero\BSN\Controllers\AccountsController;
use Montelibero\BSN\Controllers\ApiController;
use Montelibero\BSN\Controllers\AssetSwapController;
use Montelibero\BSN\Controllers\ContactsController;
use Montelibero\BSN\Controllers\DocumentsController;
use Montelibero\BSN\Controllers\Editor2Controller;
use Montelibero\BSN\Controllers\EditorController;
use Montelibero\BSN\Controllers\ErrorController;
use Montelibero\BSN\Controllers\EurmtlReportController;
use Montelibero\BSN\Controllers\FederationController;
use Montelibero\BSN\Controllers\GraphController;
use Montelibero\BSN\Controllers\GristWebhookController;
use Montelibero\BSN\Controllers\HomeController;
use Montelibero\BSN\Controllers\LoginController;
use Montelibero\BSN\Controllers\MembershipDistributionController;
use Montelibero\BSN\Controllers\MigrationController;
use Montelibero\BSN\Controllers\MtlaController;
use Montelibero\BSN\Controllers\MtlaDmReportController;
use Montelibero\BSN\Controllers\MultisigController;
use Montelibero\BSN\Controllers\PercentPayController;
use Montelibero\BSN\Controllers\ProfileEditorController;
use Montelibero\BSN\Controllers\SearchController;
use Montelibero\BSN\Controllers\SingleAccountEditTagsController;
use Montelibero\BSN\Controllers\SwapController;
use Montelibero\BSN\Controllers\TokensController;
use Montelibero\BSN\Controllers\TransactionsController;
use Montelibero\BSN\Controllers\TagsController;
use Montelibero\BSN\Controllers\VotesController;
use Montelibero\BSN\Controllers\WhoAreYouController;
use Montelibero\BSN\CurrentAccountOptions;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\CrowdConfig;
use Montelibero\BSN\CrowdIpfsClient;
use Montelibero\BSN\CrowdAccess;
use Montelibero\BSN\CrowdProjectService;
use Montelibero\BSN\DocumentsManager;
use Montelibero\BSN\EurmtlReportConfig;
use Montelibero\BSN\EurmtlReportService;
use Montelibero\BSN\KnownTagsCatalog;
use Montelibero\BSN\GristRuntimeData;
use Montelibero\BSN\GristSnapshotStore;
use Montelibero\BSN\GristSyncJobManager;
use Montelibero\BSN\GristSyncService;
use Montelibero\BSN\GristWebhookAccess;
use Montelibero\BSN\MongoCacheManager;
use Montelibero\BSN\MongoSessionHandler;
use Montelibero\BSN\RequestArrayView;
use Montelibero\BSN\RequestLocale;
use Montelibero\BSN\RequestSession;
use Montelibero\BSN\Routes\RootRoutes;
use Montelibero\BSN\StellarAccountReserveCalculator;
use Montelibero\BSN\StellarTomlCrawler;
use Montelibero\BSN\StellarTomlImageCrawler;
use Montelibero\BSN\StellarTomlImageManager;
use Montelibero\BSN\StellarTomlManager;
use Montelibero\BSN\TwigExtension;
use Montelibero\BSN\TwigPluralizeExtension;
use Montelibero\BSN\WebApp;
use Pecee\Http\Request;
use Pecee\SimpleRouter\SimpleRouter;
use MongoDB\Driver\Manager;
use Soneso\StellarSDK\StellarSDK;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use function DI\autowire;

include_once __DIR__ . '/vendor/autoload.php';

if (empty($_ENV['MONGO_HOST']) && is_file(dirname(__DIR__) . '/.env')) {
    Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

const JSON_DATA_FILE_PATH = '/var/www/bsn/bsn.json';
const IS_CLI_CONTEXT = PHP_SAPI === 'cli';
//const JSON_DATA_FILE_PATH = '../BoR/bsn.json';

//$memory1 = memory_get_usage();

$MongoManager = new Manager(
    sprintf(
        'mongodb://%s:%s@%s:%s/?authSource=%s',
        $_ENV['MONGO_ROOT_USERNAME'],
        $_ENV['MONGO_ROOT_PASSWORD'],
        $_ENV['MONGO_HOST'],
        $_ENV['MONGO_PORT'],
        $_ENV['MONGO_AUTH_SOURCE'] ?? 'admin'
    ),
    [],
    [
        'writeConcern' => new WriteConcern(1, 1000, false),
    ]
);

$Memcached = new Memcached();
$Memcached->addServer("cache", 11211);

$AccountsManager = new AccountsManager($MongoManager, $_ENV['MONGO_BASENAME']);
$ContactsManager = new ContactsManager($MongoManager, $_ENV['MONGO_BASENAME']);
$DocumentsManager = new DocumentsManager($MongoManager, $_ENV['MONGO_BASENAME']);
$GristSnapshotStore = new GristSnapshotStore($MongoManager, $_ENV['MONGO_BASENAME']);
$GristSyncJobManager = new GristSyncJobManager($MongoManager, $_ENV['MONGO_BASENAME']);
$RequestLocale = new RequestLocale();
$KnownTagsCatalog = new KnownTagsCatalog($RequestLocale, __DIR__ . '/known_tags');
$BSN = new BSN($AccountsManager, $DocumentsManager);

// Single tags
$BSN->makeTagByName('mtl_delegate')->isSingle(true);
$BSN->makeTagByName('tfm_delegate')->isSingle(true);
$BSN->makeTagByName('fcm_delegate')->isSingle(true);
$BSN->makeTagByName('mtla_c_delegate')->isSingle(true);
$BSN->makeTagByName('mtla_a_delegate')->isSingle(true);
$BSN->makeTagByName('Owner')->isSingle(true);
$BSN->makeTagByName('LeaderForMTLA')->isSingle(true);

$promoted_tags = [
    'Friend',
    'Like',
    'Dislike',
    'MyJudge',
    'ResidentME',
    'MyPart',
    'PartOf'
];
foreach ($promoted_tags as $tag_name) {
    $BSN->makeTagByName($tag_name)->isPromote(true);
}
$BSN->loadKnownTags($KnownTagsCatalog->list());

$functional_tags = [
    'Owner',
    'OwnershipFull',
    'FactionMember',
    'RecommendToMTLA',
    'RecommendForVerification',
];

if (IS_CLI_CONTEXT) {
    $_SESSION ??= [];
    $_SERVER ??= [];
    $_COOKIE ??= [];
} else {
    session_name("HELLOKITTY");
    //$session_save_path(__DIR__ . '/../sessions');
    $session_ttl_seconds = 86400 * 14;
    session_set_cookie_params([
        'lifetime' => $session_ttl_seconds,
        'path' => '/',
        'domain' => '', // Defaults to current domain
        'secure' => !($_ENV['PHP_SESSION_NO_SECURE'] ?? false),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    // DB-backed session handler
    // Keep session TTL in sync with cookie lifetime
    ini_set('session.gc_maxlifetime', (string) $session_ttl_seconds);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');

    session_set_save_handler(
        new MongoSessionHandler(
            $MongoManager,
            $_ENV['MONGO_BASENAME'],
            'sessions',
            $session_ttl_seconds
        ),
        true
    );
}

$BSN->loadFromJsonFile(JSON_DATA_FILE_PATH);

$GristSyncService = new GristSyncService($BSN, $DocumentsManager, $GristSnapshotStore);
if ($GristSnapshotStore->fetch(GristSyncService::MTLA_MEMBERS) === null) {
    try {
        $GristSyncService->syncMtlaMembers();
    } catch (\Throwable $Exception) {
        error_log('Unable to initialize MTLA members from Grist: ' . $Exception->getMessage());
    }
}
$GristRuntimeData = new GristRuntimeData($BSN, $GristSnapshotStore);
$GristRuntimeData->refreshMtlaMembersIfNeeded(true);
//$memory2 = memory_get_usage();
//print $memory2 - $memory1 . "\n";
$RequestSession = new RequestSession(!IS_CLI_CONTEXT);
$CurrentUser = new CurrentUser($BSN, $RequestSession);
$CurrentContacts = new CurrentContacts($BSN, $ContactsManager, $CurrentUser);
$AssetVersions = new AssetVersions(__DIR__);
$SessionView = new RequestArrayView();
$RequestSession->onStarted(static function () use ($SessionView): void {
    $SessionView->bind($_SESSION);
});
$ServerView = new RequestArrayView();
$Translator = new Translator($RequestLocale->getLocale());
$Translator->addLoader('yaml', new YamlFileLoader());
$Translator->addResource('yaml', __DIR__ . '/i18n/messages.ru.yaml', 'ru');
$Translator->addResource('yaml', __DIR__ . '/i18n/messages.en.yaml', 'en');
$Translator->setFallbackLocales(['en']);

$ContainerBuilder = new ContainerBuilder();
$ContainerBuilder->addDefinitions([
    // Базовые сервисы
    BSN::class => $BSN,
    Memcached::class => $Memcached,
    Manager::class => $MongoManager,
    AccountsManager::class => $AccountsManager,
    ContactsManager::class => $ContactsManager,
    DocumentsManager::class => $DocumentsManager,
    GristSnapshotStore::class => $GristSnapshotStore,
    GristSyncJobManager::class => $GristSyncJobManager,
    GristSyncService::class => $GristSyncService,
    GristRuntimeData::class => $GristRuntimeData,
    GristWebhookAccess::class => autowire(),
    MongoCacheManager::class => function() use ($MongoManager) {
        return new MongoCacheManager($MongoManager, $_ENV['MONGO_BASENAME']);
    },
    StellarTomlManager::class => function() use ($MongoManager) {
        return new StellarTomlManager($MongoManager, $_ENV['MONGO_BASENAME']);
    },
    StellarTomlImageManager::class => function() use ($MongoManager) {
        return new StellarTomlImageManager($MongoManager, $_ENV['MONGO_BASENAME']);
    },
    CurrentUser::class => $CurrentUser,
    CurrentContacts::class => $CurrentContacts,
    AssetVersions::class => $AssetVersions,
    CurrentAccountOptions::class => autowire(),
    CrowdConfig::class => autowire(),
    CrowdIpfsClient::class => autowire(),
    CrowdAccess::class => autowire(),
    CrowdProjectService::class => autowire(),
    EurmtlReportConfig::class => autowire(),
    EurmtlReportService::class => autowire(),
    RequestSession::class => $RequestSession,
    RequestLocale::class => $RequestLocale,
    KnownTagsCatalog::class => $KnownTagsCatalog,
    ApiKeysManager::class => function() use ($MongoManager) {
        return new ApiKeysManager($MongoManager, $_ENV['MONGO_BASENAME']);
    },

    Translator::class => $Translator,

    Environment::class => function(Container $container) use ($CurrentUser, $CurrentContacts, $AssetVersions, $SessionView, $ServerView, $RequestLocale) {
        $is_prod = getenv('APP_ENV') === 'prod';
        $Translator = $container->get(Translator::class);
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/twig'), [
            'cache' => $is_prod ? '/tmp/bsn-twig-cache' : false,
            'auto_reload' => !$is_prod,
        ]);
        $twig->addExtension(new TwigExtension($Translator, $AssetVersions));
        $twig->addExtension(new TranslationExtension($Translator));
        $twig->addExtension(new TwigPluralizeExtension($Translator));
        $twig->addGlobal('session', $SessionView);
        $twig->addGlobal('server', $ServerView);
        $twig->addGlobal('current_user', $CurrentUser);
        $twig->addGlobal('current_contacts', $CurrentContacts);
        $twig->addGlobal('app_locale', $RequestLocale);

        return $twig;
    },

    StellarSDK::class => function() {
//        if (($_GET['test'] ?? null) == 'true') {
            $SDK = new StellarSDK($_ENV['STELLAR_HORIZON_ENDPOINT']);
//        } else {
//            $SDK = StellarSDK::getPublicNetInstance();
//        }
        return $SDK;
    },

    WebApp::class => autowire(),
    StellarTomlCrawler::class => autowire(),
    StellarTomlImageCrawler::class => autowire(),

    AdminController::class => autowire(),
    LoginController::class => autowire(),
    AccountsController::class => autowire(),
    ApiController::class => autowire(),
    Editor2Controller::class => autowire(),
    EditorController::class => autowire(),
    ContactsController::class => autowire(),
    CrowdController::class => autowire(),
    TagsController::class => autowire(),
    TokensController::class => autowire(),
    DocumentsController::class => autowire(),
    MembershipDistributionController::class => autowire(),
    MtlaController::class => autowire(),
    MtlaDmReportController::class => autowire(),
    PercentPayController::class => autowire(),
    ProfileEditorController::class => autowire(),
    MultisigController::class => autowire(),
    VotesController::class => autowire(),
    FederationController::class => autowire(),
    GraphController::class => autowire(),
    GristWebhookController::class => autowire(),
    HomeController::class => autowire(),
    SearchController::class => autowire(),
    WhoAreYouController::class => autowire(),
    SingleAccountEditTagsController::class => autowire(),
    ErrorController::class => autowire(),
    TransactionsController::class => autowire(),
    AssetSwapController::class => autowire(),
    SwapController::class => autowire(),
    MigrationController::class => autowire(),
    EurmtlReportController::class => autowire(),
    StellarAccountReserveCalculator::class => autowire(),
]);
$Container = $ContainerBuilder->build();

if (!IS_CLI_CONTEXT) {
    RootRoutes::register($Container, $BSN, $AccountsManager);

    SimpleRouter::error(function (Request $Request, Exception $Exception) use ($Container) {
        if ($Exception->getCode() === 404) {
            $Request->setRewriteCallback(function () use ($Container) {
                return $Container->get(ErrorController::class)->Error404();
            });
        }
    });
}

function gristRequest($url, $method, $data = null)
{
    $options = [
        'http' => [
            'header' => [
                "Authorization: Bearer " . $_ENV['GRIST_API_KEY'],
                "Content-Type: application/json"
            ],
            'method' => $method,
            'content' => $data ? json_encode($data) : null,
            'ignore_errors' => true, // Позволяет получить тело ответа даже при ошибках HTTP
            'timeout' => 20,
        ]
    ];
    $context = stream_context_create($options);

    $response = file_get_contents(
        $url,
        false,
        $context
    );

    // Получаем информацию о последнем HTTP-ответе
    $response_headers = http_get_last_response_headers();
    $status_line = $response_headers[0] ?? null;
    if ($status_line === null) {
        throw new Exception('HTTP response status line is missing');
    }

    if (!preg_match('{HTTP/\S*\s(\d{3})}', $status_line, $match)) {
        throw new Exception(sprintf('HTTP response status line is invalid: %s', $status_line));
    }

    $status = (int)$match[1];

    if ($status >= 400) {
        $error_data = json_decode($response, true);
        throw new Exception(
            sprintf(
                "HTTP %s: %s",
                $status,
                $error_data['error'] ?? $response
            )
        );
    }

    return json_decode($response, true);
}

return new ApplicationContext(
    $Container,
    $RequestSession,
    $RequestLocale,
    $Translator,
    $CurrentUser,
    $CurrentContacts,
    $SessionView,
    $ServerView,
    $BSN,
    $GristRuntimeData,
    JSON_DATA_FILE_PATH,
);
