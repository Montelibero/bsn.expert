<?php

use DI\Container;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Montelibero\BSN\AccountsManager;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\AccountsController;
use Montelibero\BSN\Controllers\ContactsController;
use Montelibero\BSN\Controllers\ContractsController;
use Montelibero\BSN\Controllers\EditorController;
use Montelibero\BSN\Controllers\MembershipDistributionController;
use Montelibero\BSN\Controllers\MtlaController;
use Montelibero\BSN\Controllers\MultisigController;
use Montelibero\BSN\Controllers\PercentPayController;
use Montelibero\BSN\Controllers\TagsController;
use Montelibero\BSN\Routes\RootRoutes;
use Montelibero\BSN\TwigExtension;
use Montelibero\BSN\TwigPluralizeExtension;
use Montelibero\BSN\WebApp;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\StellarSDK;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use function DI\autowire;

include_once __DIR__ . '/vendor/autoload.php';

// Загружаем переменные окружения из файла .env
(Dotenv::createImmutable(__DIR__))->load();

const JSON_DATA_FILE_PATH = '/var/www/bsn.mtla.me/app/bsn.json';
//const JSON_DATA_FILE_PATH = '../BoR/bsn.json';

//$memory1 = memory_get_usage();

$PDO = new PDO(
    'mysql:host=' . $_ENV['MYSQL_HOST'] . ';dbname=' . $_ENV['MYSQL_BASENAME'],
    $_ENV['MYSQL_USERNAME'],
    $_ENV['MYSQL_PASSWORD']
);
$PDO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$AccountsManager = new AccountsManager($PDO);
$BSN = new BSN($AccountsManager);

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
$known_tags = json_decode(file_get_contents('./known_tags.json'), JSON_OBJECT_AS_ARRAY);
foreach ($known_tags['links'] as $link_name => $link_data) {
    if ($pair = ($link_data['pair'] ?? false)) {
        $Tag = $BSN->makeTagByName($link_name);
        $TagPair = $link_data['pair'] === true ? $Tag : $BSN->makeTagByName($pair);
        $Tag->setPair($TagPair, $link_data['strong_pair'] ?? false);
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
session_set_cookie_params([
    'lifetime' => 86400 * 14,
    'path' => '/',
    'domain' => '', // Defaults to current domain
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
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

$ContainerBuilder = new ContainerBuilder();
$ContainerBuilder->addDefinitions([
    // Базовые сервисы
    BSN::class => $BSN,
    AccountsManager::class => $AccountsManager,

    Translator::class => function() {
        $locale = 'en';
        if (stripos($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 'ru') !== false) {
            $locale = 'ru';
        }
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', __DIR__ . '/i18n/messages.ru.yaml', 'ru');
        $translator->addResource('yaml', __DIR__ . '/i18n/messages.en.yaml', 'en');
        $translator->setFallbackLocales(['en']);
        
        return $translator;
    },

    Environment::class => function(Container $container) {
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/twig'));
        $twig->addExtension(new TwigExtension());
        $twig->addExtension(new TranslationExtension($container->get(Translator::class)));
        $twig->addExtension(new TwigPluralizeExtension($container->get(Translator::class)));
        
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

    PDO::class => $PDO,

    WebApp::class => autowire(),

    AccountsController::class => autowire(),
    EditorController::class => autowire(),
    ContactsController::class => autowire(),
    TagsController::class => autowire(),
    ContractsController::class => autowire(),
    MembershipDistributionController::class => autowire(),
    MtlaController::class => autowire(),
    PercentPayController::class => autowire(),
    MultisigController::class => autowire(),
]);
$Container = $ContainerBuilder->build();

RootRoutes::register($Container, $BSN, $AccountsManager);

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
    preg_match('{HTTP\/\S*\s(\d{3})}', $status_line, $match);
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
