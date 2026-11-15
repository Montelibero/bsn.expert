<?php

declare(strict_types=1);

use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\AdminController;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\RequestSession;
use Montelibero\BSN\StellarTomlManager;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

error_reporting(E_ALL & ~E_DEPRECATED);
ob_start();

require dirname(__DIR__) . '/vendor/autoload.php';

function assertTomlNeighbors(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$manager = new class extends StellarTomlManager {
    public array $writes = [];
    private array $domains = [];
    public function __construct() {}
    public function setDomainNeighbor(string $home_domain, bool $neighbor, ?string $admin_account_id = null): ?array
    {
        $this->writes[] = [$home_domain, $neighbor, $admin_account_id];
        $home_domain = self::normalizeHomeDomain($home_domain);
        if ($home_domain === null) {
            return null;
        }
        return $this->domains[$home_domain] = ['home_domain' => $home_domain, 'neighbor' => $neighbor];
    }
    public function fetchDashboardData(int $problem_limit = 200): array
    {
        return ['neighbor_domains' => array_filter($this->domains, static fn (array $doc): bool => $doc['neighbor'])];
    }
};
$session = new RequestSession(false);
$user = new CurrentUser((new ReflectionClass(BSN::class))->newInstanceWithoutConstructor(), $session);
$translator = new Translator('ru');
$translator->addLoader('yaml', new YamlFileLoader());
foreach (['ru', 'en'] as $locale) {
    $translator->addResource('yaml', dirname(__DIR__) . '/i18n/messages.' . $locale . '.yaml', $locale);
}
$twig = new Environment(new ChainLoader([
    new ArrayLoader(['page.twig' => '{% block content %}{% endblock %}']),
    new FilesystemLoader(dirname(__DIR__) . '/twig'),
]));
$twig->addExtension(new TranslationExtension($translator));
$reflection = new ReflectionClass(AdminController::class);
$controller = $reflection->newInstanceWithoutConstructor();
foreach (['CurrentUser' => $user, 'RequestSession' => $session, 'TomlManager' => $manager, 'Twig' => $twig, 'Translator' => $translator] as $property => $value) {
    $reflection->getProperty($property)->setValue($controller, $value);
}

$_ENV['ADMINS'] = 'GADMIN';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION = ['current_account_id' => 'GADMIN'];
$_POST = ['action' => 'add_neighbor', 'home_domain' => 'eurmtl.me', 'csrf_token' => 'wrong'];
assertTomlNeighbors($controller->Tomls() === null && http_response_code() === 404 && $manager->writes === [], 'Selecting an admin account does not grant permission.');
$_SESSION['account'] = ['id' => 'GOUTSIDER'];
assertTomlNeighbors($controller->Tomls() === null && http_response_code() === 404 && $manager->writes === [], 'A non-admin signer cannot add neighbours.');
$_SESSION['account'] = ['id' => 'GADMIN'];
assertTomlNeighbors($controller->Tomls() === 'Bad CSRF token' && http_response_code() === 400 && $manager->writes === [], 'Adding neighbours requires the existing admin CSRF token.');

$_POST['csrf_token'] = $session->getOrCreateToken('csrf:admin_tomls');
$_POST['home_domain'] = ' EURMTL.ME ';
$html = $controller->Tomls();
assertTomlNeighbors($manager->writes === [[' EURMTL.ME ', true, 'GADMIN']], 'The controller must persist the flag with the authenticated admin identity.');
assertTomlNeighbors(str_contains($html, 'eurmtl.me добавлен в соседи') && str_contains($html, 'value="remove_neighbor"'), 'Adding a neighbour must show a translated notice and removal form.');
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
$xpath = new DOMXPath($dom);
foreach ($xpath->query('//form[input[@name="action" and (@value="add_neighbor" or @value="remove_neighbor")]]') as $form) {
    assertTomlNeighbors($xpath->evaluate('string(input[@name="csrf_token"]/@value)', $form) === $_POST['csrf_token'], 'Every neighbour mutation form must carry CSRF protection.');
}

$_POST = ['action' => 'remove_neighbor', 'home_domain' => 'eurmtl.me', 'csrf_token' => 'wrong'];
assertTomlNeighbors($controller->Tomls() === 'Bad CSRF token' && count($manager->writes) === 1, 'Removal must also enforce CSRF.');
$_POST['csrf_token'] = $session->getOrCreateToken('csrf:admin_tomls');
$translator->setLocale('en');
$html = $controller->Tomls();
assertTomlNeighbors($manager->writes[1] === ['eurmtl.me', false, 'GADMIN'], 'Removal must revoke the stored permission.');
assertTomlNeighbors(str_contains($html, 'eurmtl.me removed from neighbours') && str_contains($html, 'No neighbour domains added.'), 'Removal and empty state must render in English.');
$_POST = ['action' => 'add_neighbor', 'home_domain' => '*.eurmtl.me', 'csrf_token' => $_POST['csrf_token']];
assertTomlNeighbors(str_contains($controller->Tomls(), 'Enter a domain name'), 'Invalid input must show the translated validation error.');

ob_end_clean();
fwrite(STDOUT, "Stellar TOML neighbour admin tests passed.\n");
