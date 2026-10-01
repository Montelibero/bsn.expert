<?php

declare(strict_types=1);

use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Montelibero\BSN\Controllers\VotesController;
use Montelibero\BSN\CurrentUser;
use Montelibero\BSN\Relations\Corporate;
use Montelibero\BSN\Relations\Person;
use Montelibero\BSN\RequestSession;
use Soneso\StellarSDK\Crypto\KeyPair;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

error_reporting(E_ALL & ~E_DEPRECATED);
ob_start();

require dirname(__DIR__) . '/vendor/autoload.php';

function assertVotesAccess(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$member_id = KeyPair::random()->getAccountId();
$corporate_id = KeyPair::random()->getAccountId();
$outsider_id = KeyPair::random()->getAccountId();
$Member = Account::fromId($member_id);
$Member->setRelation(new Person(1));
$Corporate = Account::fromId($corporate_id);
$Corporate->setRelation(new Corporate(1));
$Outsider = Account::fromId($outsider_id);
$BSN = new class([$member_id => $Member, $corporate_id => $Corporate, $outsider_id => $Outsider]) extends BSN {
    public function __construct(private readonly array $accounts) {}
    public function makeAccountById(string $id): Account { return $this->accounts[$id] ?? Account::fromId($id); }
};
$CurrentUser = new CurrentUser($BSN, new RequestSession(false));
$Translator = new Translator('ru');
$Translator->addLoader('yaml', new YamlFileLoader());
$Translator->addResource('yaml', dirname(__DIR__) . '/i18n/messages.ru.yaml', 'ru');
$Twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/twig'));
$Twig->addExtension(new TranslationExtension($Translator));
$Twig->addFunction(new TwigFunction('asset_url', static fn (string $path): string => $path));
$Twig->addGlobal('current_user', $CurrentUser);
$Twig->addGlobal('current_contacts', new class {
    public function getContactName(Account $account): ?string { return null; }
    public function displayName(array $data): ?string { return null; }
});
$Twig->addGlobal('server', ['REQUEST_URI' => '/mtla/']);
$Controller = new VotesController($BSN, $Twig, $Translator, $CurrentUser);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['d' => ['aaaaaaaaaaaaaaaaaaaa']];
$_SESSION = ['current_account_id' => $member_id];
http_response_code(200);
$html = $Controller->MtlaVotes();
assertVotesAccess(http_response_code() === 403, 'A selected member account must not authorize an anonymous visitor.');
assertVotesAccess(str_contains($html, 'только для авторизованных участников Ассоциации'), 'Direct access must explain the restriction.');
assertVotesAccess(!str_contains($html, 'name="links"'), 'An unauthorized visitor must not see the tool form.');
$mtla_html = $Twig->render('mtla.twig', ['mtla_account' => ['id' => $member_id]]);
assertVotesAccess(!str_contains($mtla_html, 'href="/tools/mtla/votes"'), 'The link must be hidden from an anonymous visitor.');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['links' => 'https://docs.google.com/spreadsheets/d/aaaaaaaaaaaaaaaaaaaa'];
$_SESSION = ['account' => ['id' => $outsider_id], 'current_account_id' => $member_id];
http_response_code(200);
$html = $Controller->MtlaVotes();
assertVotesAccess(http_response_code() === 403, 'A selected member account must not authorize an unrelated signer on POST.');
assertVotesAccess(!str_contains($html, 'name="links"'), 'An unauthorized POST must not expose the tool form.');
$mtla_html = $Twig->render('mtla.twig', ['mtla_account' => ['id' => $member_id]]);
assertVotesAccess(!str_contains($mtla_html, 'href="/tools/mtla/votes"'), 'The link must be hidden from an unrelated signer.');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];
$_SESSION = ['account' => ['id' => $member_id], 'current_account_id' => $outsider_id];
http_response_code(200);
$html = $Controller->MtlaVotes();
assertVotesAccess(http_response_code() === 200 && str_contains($html, 'name="links"'), 'An authenticated level-one member must access the tool.');
$mtla_html = $Twig->render('mtla.twig', ['mtla_account' => ['id' => $member_id]]);
assertVotesAccess(str_contains($mtla_html, 'href="/tools/mtla/votes"'), 'A level-one member must see the tool link.');
assertVotesAccess(!str_contains($mtla_html, 'href="/tools/mtla/send_time_tokens"'), 'Other actions must retain their existing activist gate.');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['links' => ''];
http_response_code(200);
$html = $Controller->MtlaVotes();
assertVotesAccess(http_response_code() === 200 && str_contains($html, 'name="links"'), 'An authenticated member must still use POST.');

$Member->setRelation(new Person(4));
$Member->addBalanceRecord('MTLAP', 4.0);
$mtla_html = $Twig->render('mtla.twig', ['mtla_account' => ['id' => $member_id]]);
assertVotesAccess(str_contains($mtla_html, 'href="/tools/mtla/send_time_tokens"'), 'Existing activist actions must remain visible to activists.');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = ['account' => ['id' => $corporate_id]];
http_response_code(200);
$html = $Controller->MtlaVotes();
assertVotesAccess(http_response_code() === 200 && str_contains($html, 'name="links"'), 'An authenticated corporate member must access the tool.');

ob_end_clean();
fwrite(STDOUT, "MTLA votes access tests passed.\n");
