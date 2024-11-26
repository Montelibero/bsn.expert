<?php

use Dotenv\Dotenv;
use Montelibero\BSN\BSN;
use Montelibero\BSN\TwigExtension;
use Montelibero\BSN\WebApp;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\StellarSDK;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

include_once __DIR__ . '/vendor/autoload.php';

// Загружаем переменные окружения из файла .env
(Dotenv::createImmutable(__DIR__))->load();

const JSON_DATA_FILE_PATH = '/var/www/bsn.mtla.me/app/bsn.json';
//const JSON_DATA_FILE_PATH = '../BoR/bsn.json';

//$memory1 = memory_get_usage();
$BSN = new BSN();

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
    'A', 'B', 'C', 'D',
    'Spouse', 'Love', 'OneFamily', 'Guardian', 'Ward', 'Sympathy', 'Divorce',
    'Employer', 'Employee', 'Contractor', 'Client', 'Partnership', 'Collaboration',
    'Owner', 'OwnershipFull', 'OwnerMajority', 'OwnershipMajority', 'OwnerMinority',
    'FactionMember', 'WelcomeGuest',
];
foreach ($standard_tags as $tag_name) {
    $BSN->makeTagByName($tag_name)->isStandard(true);
}
$promoted_tags = [
    'Friend', 'Like', 'Dislike', 'MyJudge',
    'ResidentME',
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
$BSN->loadMtlaMembersFromJson(json_decode(file_get_contents('../mtla_members.json'), JSON_OBJECT_AS_ARRAY));
$BSN->loadContacts();
//$memory2 = memory_get_usage();
//print $memory2 - $memory1 . "\n";

$Twig = new Environment(new FilesystemLoader(__DIR__ . '/twig'));
$Twig->addExtension(new TwigExtension());

$WebApp = new WebApp($BSN, $Twig, StellarSDK::getPublicNetInstance());

$Router = new SimpleRouter();

SimpleRouter::get('/', function () use ($WebApp) {
    return $WebApp->Index();
})->name('root');
SimpleRouter::get('/tg', function () use ($WebApp) {
    return $WebApp->TgLogin();
});
SimpleRouter::get('/tg/logout', function () use ($WebApp) {
    return $WebApp->TgLogout();
});
SimpleRouter::get('/accounts/', function () use ($WebApp) {
    return $WebApp->Accounts();
});
SimpleRouter::get('/accounts/{id}', function ($id) use ($WebApp) {
    return $WebApp->Account($id);
})->name('account');
SimpleRouter::get('/tags/', function () use ($WebApp) {
    return $WebApp->Tags();
});
SimpleRouter::get('/tags/{id}', function ($id) use ($WebApp) {
    return $WebApp->Tag($id);
})->name('tag');
SimpleRouter::group(['prefix' => '/mtla'], function() use ($WebApp) {
    SimpleRouter::get('/', function () use ($WebApp) {
        return $WebApp->Mtla();
    });
    SimpleRouter::get('/council', function () use ($WebApp) {
        return $WebApp->MtlaCouncil();
    });
});
SimpleRouter::get('/editor/', function () use ($WebApp) {
    return $WebApp->EditorForm();
});
SimpleRouter::get('/editor/{id}', function ($id) use ($WebApp) {
    return $WebApp->Editor($id);
})->name('editor');
SimpleRouter::post('/editor/{id}', function ($id) use ($WebApp) {
    return $WebApp->EditorSave($id);
});
SimpleRouter::match(['get', 'post'], '/contacts', function () use ($WebApp) {
    return $WebApp->Contacts();
});
SimpleRouter::match(['get', 'post'], '/defaults', function () use ($WebApp) {
    return $WebApp->Defaults();
});

SimpleRouter::start();
