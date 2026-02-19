<?php

use DI\Container;
use DI\ContainerBuilder;
use MongoDB\Driver\WriteConcern;
use Montelibero\BSN\AccountsManager;
use Montelibero\BSN\ApiKeysManager;
use Montelibero\BSN\BSN;
use Montelibero\BSN\ContactsManager;
use Montelibero\BSN\Controllers\AccountsController;
use Montelibero\BSN\Controllers\ApiController;
use Montelibero\BSN\Controllers\ContactsController;
use Montelibero\BSN\Controllers\DocumentsController;
use Montelibero\BSN\Controllers\EditorController;
use Montelibero\BSN\Controllers\ErrorController;
use Montelibero\BSN\Controllers\FederationController;
use Montelibero\BSN\Controllers\LoginController;
use Montelibero\BSN\Controllers\MembershipDistributionController;
use Montelibero\BSN\Controllers\MtlaController;
use Montelibero\BSN\Controllers\MultisigController;
use Montelibero\BSN\Controllers\PercentPayController;
use Montelibero\BSN\Controllers\TokensController;
use Montelibero\BSN\Controllers\TransactionsController;
use Montelibero\BSN\Controllers\TagsController;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\DocumentsManager;
use Montelibero\BSN\MongoSessionHandler;
use Montelibero\BSN\Routes\RootRoutes;
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

const JSON_DATA_FILE_PATH = '/var/www/bsn/bsn.json';
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
$BSN = new BSN($AccountsManager, $ContactsManager, $DocumentsManager);

$BSN->makeTagByName('Signer')->isEditable(false);

// Single tags
$BSN->makeTagByName('mtl_delegate')->isSingle(true);
$BSN->makeTagByName('tfm_delegate')->isSingle(true);
$BSN->makeTagByName('fcm_delegate')->isSingle(true);
$BSN->makeTagByName('mtla_c_delegate')->isSingle(true);
$BSN->makeTagByName('mtla_a_delegate')->isSingle(true);
$BSN->makeTagByName('Owner')->isSingle(true);
$BSN->makeTagByName('LeaderForMTLA')->isSingle(true);

// Standards tags
$standard_tags = [
    'A',
    'B',
    'C',
    'D',
    'Spouse',
    'Love',
    'OneFamily',
    'Guardian',
    'Ward',
    'Sympathy',
    'Divorce',
    'Employer',
    'Employee',
    'Contractor',
    'Client',
    'Partnership',
    'Collaboration',
    'Owner',
    'OwnershipFull',
    'OwnerMajority',
    'OwnershipMajority',
    'OwnerMinority',
    'FactionMember',
    'WelcomeGuest',
];
foreach ($standard_tags as $tag_name) {
    $BSN->makeTagByName($tag_name)->isStandard(true);
}
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
$known_tags = json_decode(file_get_contents('./list.json'), JSON_OBJECT_AS_ARRAY);
foreach ($known_tags['links'] as $link_name => $link_data) {
    if ($pair = ($link_data['pair'] ?? false)) {
        $Tag = $BSN->makeTagByName($link_name);
        $TagPair = $link_data['pair'] === true ? $Tag : $BSN->makeTagByName($pair);
        $Tag->setPair($TagPair, $link_data['strong_pair'] ?? false);
    }
    if ($known_tags['single'] ?? null) {
        $Tag = $BSN->makeTagByName($link_name);
        $Tag->isSingle($known_tags['single']);
    }
}

$functional_tags = [
    'Owner',
    'OwnershipFull',
    'FactionMember',
    'RecommendToMTLA',
    'RecommendForVerification',
];

session_name("HELLOKITTY");
//session_save_path(__DIR__ . '/../sessions');
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

session_start();

$BSN->loadFromJson(json_decode(file_get_contents(JSON_DATA_FILE_PATH), JSON_OBJECT_AS_ARRAY));
if (!apcu_exists('mtla_members')) {
    MtlaController::reloadMembers();
}
$mtla_members = apcu_fetch('mtla_members');
$BSN->loadMtlaMembersFromJson($mtla_members);
$BSN->loadContacts();
//$memory2 = memory_get_usage();
//print $memory2 - $memory1 . "\n";
$CurrentUser = new CurrentUser($_SESSION, $BSN);

$ContainerBuilder = new ContainerBuilder();
$ContainerBuilder->addDefinitions([
    // Базовые сервисы
    BSN::class => $BSN,
    Memcached::class => $Memcached,
    Manager::class => $MongoManager,
    AccountsManager::class => $AccountsManager,
    ContactsManager::class => $ContactsManager,
    DocumentsManager::class => $DocumentsManager,
    CurrentUser::class => $CurrentUser,
    ApiKeysManager::class => function() use ($MongoManager) {
        return new ApiKeysManager($MongoManager, $_ENV['MONGO_BASENAME']);
    },

    Translator::class => function() {
        $locale = 'en';
        if (stripos($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 'ru') !== false) {
            $locale = 'ru';
        }
        $locale = $_COOKIE['language'] ?? $locale;
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', __DIR__ . '/i18n/messages.ru.yaml', 'ru');
        $translator->addResource('yaml', __DIR__ . '/i18n/messages.en.yaml', 'en');
        $translator->setFallbackLocales(['en']);
        
        return $translator;
    },

    Environment::class => function(Container $container) use ($CurrentUser) {
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/twig'));
        $twig->addExtension(new TwigExtension());
        $twig->addExtension(new TranslationExtension($container->get(Translator::class)));
        $twig->addExtension(new TwigPluralizeExtension($container->get(Translator::class)));
        $twig->addGlobal('session', $_SESSION);
        $twig->addGlobal('server', $_SERVER);
        $twig->addGlobal('current_user', $CurrentUser);

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

    LoginController::class => autowire(),
    AccountsController::class => autowire(),
    ApiController::class => autowire(),
    EditorController::class => autowire(),
    ContactsController::class => autowire(),
    TagsController::class => autowire(),
    TokensController::class => autowire(),
    DocumentsController::class => autowire(),
    MembershipDistributionController::class => autowire(),
    MtlaController::class => autowire(),
    PercentPayController::class => autowire(),
    MultisigController::class => autowire(),
    FederationController::class => autowire(),
    ErrorController::class => autowire(),
    TransactionsController::class => autowire(),
]);
$Container = $ContainerBuilder->build();

RootRoutes::register($Container, $BSN, $AccountsManager);

SimpleRouter::error(function (Request $Request, Exception $Exception) use ($Container) {
    if ($Exception->getCode() === 404) {
        $Request->setRewriteCallback(function () use ($Container) {
            return $Container->get(ErrorController::class)->Error404();
        });
    }
});

SimpleRouter::start();

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
            'ignore_errors' => true // Позволяет получить тело ответа даже при ошибках HTTP
        ]
    ];
    $context = stream_context_create($options);

    $response = file_get_contents(
        $url,
        false,
        $context
    );

    // Получаем информацию о последнем HTTP-ответе
    $status_line = $http_response_header[0];
    preg_match('{HTTP/\S*\s(\d{3})}', $status_line, $match);
    $status = $match[1];

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
