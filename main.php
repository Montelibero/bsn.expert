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
    'Friend', 'Antipathy', 'Like', 'Dislike', 'MyJudge',
];
foreach ($promoted_tags as $tag_name) {
    $BSN->makeTagByName($tag_name)->isPromote(true);
}


$BSN->loadFromJson(json_decode(file_get_contents(JSON_DATA_FILE_PATH), JSON_OBJECT_AS_ARRAY));
$BSN->loadMtlaMembersFromJson(json_decode(file_get_contents('../mtla_members.json'), JSON_OBJECT_AS_ARRAY));
//$memory2 = memory_get_usage();
//print $memory2 - $memory1 . "\n";

$Twig = new Environment(new FilesystemLoader(__DIR__ . '/twig'));
$Twig->addExtension(new TwigExtension());

session_set_cookie_params(86400 * 7);
session_start();

$WebApp = new WebApp($BSN, $Twig, StellarSDK::getPublicNetInstance());

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
SimpleRouter::get('/mtla/', function () use ($WebApp) {
    return $WebApp->Mtla();
});
SimpleRouter::get('/mtla/council/', function () use ($WebApp) {
    return $WebApp->MtlaCouncil();
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

SimpleRouter::start();
