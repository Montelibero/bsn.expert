<?php

namespace Montelibero\BSN\Controllers;

use League\Uri\Exceptions\SyntaxError;
use League\Uri\Http;
use Montelibero\BSN\Account;
use Montelibero\BSN\BSN;
use Montelibero\BSN\ContactsManager;
use Montelibero\BSN\Relations\Member;
use Montelibero\BSN\Tag;
use Montelibero\BSN\WebApp;
use Pecee\SimpleRouter\SimpleRouter;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\AssetTypeNative;
use Soneso\StellarSDK\ClawbackOperationBuilder;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\MuxedAccount;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\SetTrustLineFlagsOperationBuilder;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use splitbrain\phpQRCode\QRCode;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class EditorController
{
    private BSN $BSN;
    private Environment $Twig;
    private StellarSDK $Stellar;

    public function __construct(BSN $BSN, Environment $Twig, StellarSDK $Stellar, Translator $Translator)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);

        $this->Stellar = $Stellar;
    }

    public function EditorForm(): string
    {
        $single_tag = BSN::validateTagNameFormat($_GET['tag'] ?? null)
            ? $_GET['tag']
            : null;
        $single_contact = BSN::validateStellarAccountIdFormat($_GET['contact'] ?? null)
            ? $_GET['contact']
            : null;

        if (($id = $_GET['id'] ?? null) && $this->BSN::validateStellarAccountIdFormat($id)) {
            $filter_query = [];
            if ($single_tag) {
                $filter_query['tag'] = $single_tag;
            }
            if ($single_contact) {
                $filter_query['id'] = $single_contact;
            }

            SimpleRouter::response()->redirect(
                '/editor/' . $id . '/'
                . ($filter_query ? '?' . http_build_query($filter_query) : ''),
                302
            );
        }

        $Template = $this->Twig->load('editor_form.twig');
        return $Template->render([
            'default_id' => $_GET['id'] ?? $_SESSION['account']['id'] ?? '',
            'single_tag' => $single_tag,
            'single_contact' => $single_contact,
        ]);
    }

    /**
     * @param Account $Account
     * @return Tag[]
     */
    private function decideEditableTags(Account $Account): array
    {
        $tags = [];
        foreach ($this->BSN->getTags() as $Tag) {
            if ($Tag->isStandard() || $Tag->isPromote()) {
                $tags[] = $Tag;
            }
        }

        $tags = array_merge($tags, $Account->getOutcomeTags(), $Account->getIncomeTags());

        $tags = array_filter($tags, fn(Tag $Tag) => $Tag->isEditable());

        $tags = array_combine(
            array_map(fn($Tag) => $Tag->getName(), $tags),
            $tags
        );

        usort($tags, function ($a, $b) {
            // Получаем названия тегов
            $nameA = $a->getName();
            $nameB = $b->getName();

            // Ищем позиции тегов в массиве $sort_example
            $indexA = array_search($nameA, WebApp::$sort_tags_example);
            $indexB = array_search($nameB, WebApp::$sort_tags_example);

            // Если оба тега есть в $sort_example, сортируем по их позициям
            if ($indexA !== false && $indexB !== false) {
                return $indexA - $indexB;
            }

            // Если один из тегов есть в $sort_example, а другой нет, придаём приоритет тому, что есть в $sort_example
            if ($indexA !== false) {
                return -1; // $a имеет приоритет, т.к. он есть в $sort_example
            }
            if ($indexB !== false) {
                return 1; // $b имеет приоритет, т.к. он есть в $sort_example
            }

            // Если ни один из тегов не найден в $sort_example, сортируем их по алфавиту
            return strcmp($nameA, $nameB);
        });

        return $tags;
    }

    /**
     * @param Tag[] $tags
     * @return array
     */
    private function decideTagGroups(array $tags): array
    {
        $groups = [
            'Social' => [
                'Friend',
                'Like',
                'Dislike',
            ],
            'Credit' => [
                'A',
                'B',
                'C',
                'D',
            ],
            'Family' => [
                'Spouse',
                'Love',
                'OneFamily',
                'Guardian',
                'Ward',
            ],
            'Partnership' => [
                'Employer',
                'Employee',
                'Contractor',
                'Client',
                'Partnership',
                'Collaboration',
            ],
            'Ownership' => [
                'Owner',
                'OwnershipFull',
                'OwnerMajority',
                'OwnershipMajority',
                'OwnerMinority',
            ],
            'MTLA' => [
                'FactionMember',
                'RecommendToMTLA',
                'RecommendForVerification',
                'LeaderForMTLA',
            ],
            'Delegation' => [
                'mtl_delegate',
                'tfm_delegate',
                'mtla_c_delegate',
                'mtla_a_delegate',
            ],
            'Other' => [],
        ];

        $tag_groups = [];

        // Обход всех тегов
        foreach ($tags as $tag) {
            $tag_name = $tag->getName();
            $found = false;

            // Поиск, к какой группе принадлежит тег
            foreach ($groups as $group_name => $group_tags) {
                if (in_array($tag_name, $group_tags, true)) {
                    // Инициализация группы при первом добавлении
                    if (!isset($tag_groups[$group_name])) {
                        $tag_groups[$group_name] = [];
                    }
                    $tag_groups[$group_name][] = $tag_name;
                    $found = true;
                    break;
                }
            }

            // Если тег не нашелся в группах, добавляем его в Others
            if (!$found) {
                $tag_groups['Others'][] = $tag_name;
            }
        }

        WebApp::semantic_sort_keys($tag_groups, array_keys($groups));

        return $tag_groups;
    }

    public function Editor($id): string
    {
        if (!$this->BSN::validateStellarAccountIdFormat($id)) {
            SimpleRouter::response()->redirect('/editor/', 302);
        }

        $Account = $this->BSN->makeAccountById($id);

        $single_tag = BSN::validateTagNameFormat($_GET['tag'] ?? null) ? $_GET['tag'] : null;
        $single_contact = BSN::validateStellarAccountIdFormat($_GET['id'] ?? null) ? $_GET['id'] : null;

        /** @var Tag[] $tags */
        $tags = [];
        if ($single_tag) {
            $tags[$single_tag] = $this->BSN->makeTagByName($single_tag);
        } else {
            $tags = $this->decideEditableTags($Account);
        }

        $group_tags = $this->decideTagGroups($tags);

        /** @var Account[] $contacts */
        $contacts = [];
        if ($single_contact) {
            $contacts[$single_contact] = $this->BSN->makeAccountById($single_contact);
        } else {
            foreach ($tags as $Tag) {
                foreach (array_merge($Account->getOutcomeLinks($Tag), $Account->getIncomeLinks($Tag)) as $Contact) {
                    $contacts[$Contact->getId()] = $Contact;
                }
            }
            // Add from contact book
            if ($_SESSION['account'] ?? null) {
                $ContactsManager = new ContactsManager($_SESSION['account']['id']);
                foreach ($ContactsManager->getContacts() as $stellar_address => $item) {
                    if (!array_key_exists($stellar_address, $contacts)) {
                        $contacts[$stellar_address] = $this->BSN->makeAccountById($stellar_address);
                    }
                }
            }
        }

        $values = [];
        $single_tag_has_value = [];
        foreach ($Account->getOutcomeTags() as $Tag) {
            if (!$Tag->isEditable()) {
                continue;
            }
            foreach ($Account->getOutcomeLinks($Tag) as $Contact) {
                $values[$Contact->getId()] = $values[$Contact->getId()] ?? [];
                $values[$Contact->getId()][$Tag->getName()] = true;

                if ($Tag->isSingle()) {
                    $single_tag_has_value[$Tag->getName()] = true;
                }
            }
        }

        $Template = $this->Twig->load('editor.twig');
        return $Template->render([
            'account' => $Account->jsonSerialize(),
            'single_tag' => $single_tag,
            'single_contact' => $single_contact,
            'tags' => array_map(fn(Tag $Tag) => [
                'name' => $Tag->getName(),
                'is_single' => $Tag->isSingle(),
            ], $tags),
            'group_tags' => $group_tags,
            'contacts' => array_map(fn($Contact) => $Contact->jsonSerialize(), $contacts),
            'values' => $values,
            'single_tag_has_value' => $single_tag_has_value,
        ]);
    }

    public function EditorSave($id): string
    {
        if (!$this->BSN::validateStellarAccountIdFormat($id)) {
            SimpleRouter::response()->redirect('/editor/', 302);
        }

        $Account = $this->BSN->makeAccountById($id);

        $single_tag = BSN::validateTagNameFormat($_POST['single_tag'] ?? null)
            ? $_POST['single_tag']
            : null;
        $single_contact = BSN::validateStellarAccountIdFormat($_POST['single_contact'] ?? null)
            ? $_POST['single_contact']
            : null;

        $working_tags = $single_tag ? [$this->BSN->makeTagByName($single_tag)] : $Account->getOutcomeTags();

        $current_values = [];

        foreach ($working_tags as $Tag) {
            if (!$Tag->isEditable()) {
                continue;
            }
            if ($Tag->isSingle()) {
                $current_values[$Tag->getName()] = null;
            }
            foreach ($Account->getOutcomeLinks($Tag) as $Contact) {
                if ($single_contact && $Contact->getId() !== $single_contact) {
                    continue;
                }
                if ($Tag->isSingle()) {
                    $current_values[$Tag->getName()] = $Contact->getId();
                } else {
                    $current_values[$Tag->getName()] = $current_values[$Tag->getName()] ?? [];
                    $current_values[$Tag->getName()][$Contact->getId()] = true;
                }
            }
        }

        //        print '<pre>';

        // Removed
        $to_remove = [];
        foreach ($current_values as $tag_name => $data) {
            $Tag = $this->BSN->getTag($tag_name);
            if ($Tag->isSingle()) {
                if (array_key_exists($tag_name, $_POST['tag'] ?? []) && !$_POST['tag'][$tag_name]) {
                    $to_remove[$tag_name] = true;
                }
            } else {
                foreach ($data as $account_id => $on) {
                    if (!isset($_POST['tag'][$tag_name][$account_id])) {
                        $to_remove[$tag_name] = $to_remove[$tag_name] ?? [];
                        $to_remove[$tag_name][] = $account_id;
                    }
                }
            }
        }
        //        print "Remove:\n";
        //        print_r($to_remove);

        // Added
        $to_add = [];
        foreach ($_POST['tag'] as $tag_name => $data) {
            $Tag = $this->BSN->getTag($tag_name);
            if ($Tag->isSingle()) {
                if ($_POST['tag'][$tag_name] && $_POST['tag'][$tag_name] !== $current_values[$tag_name]) {
                    $to_add[$tag_name] = $data;
                }
            } else {
                foreach ($data as $account_id => $on) {
                    if (!isset($current_values[$tag_name][$account_id])) {
                        $to_add[$tag_name] = $to_add[$tag_name] ?? [];
                        $to_add[$tag_name][] = $account_id;
                    }
                }
            }
        }
        //        print "Add:\n";
        //        print_r($to_add);

        $last_tag_sufix = [];
        $to_remove_data_keys = [];
        foreach ($this->fetchAccountData($id) as $key_name => $value) {
            $value = base64_decode($value);
            if (!$this->BSN::validateStellarAccountIdFormat($value)) {
                continue;
            }
            if (!preg_match(
                '/^\s*(?<tag>[a-z0-9_]+?)\s*(:\s*(?<extra>[a-z0-9_]+?))?\s*(?<sufix>\d*)\s*$/i',
                $key_name,
                $m
            )) {
                continue;
            }
            $tag = $m['tag'] . ($m['extra'] ? ':' . $m['extra'] : '');
            //            var_dump($tag);
            $last_tag_sufix[$tag] = max($last_tag_sufix[$tag] ?? 0, $m['sufix'] ?: 0);
            if (array_key_exists($tag, $to_remove)) {
                if (
                    (is_array($to_remove[$tag]) && in_array($value, $to_remove[$tag]))
                    || !is_array($to_remove[$tag])
                ) {
                    //                  print_r($key_name);
                    $to_remove_data_keys[] = $key_name;
                }
            }
        }
        //        print "Sufixes:\n";
        //        print_r($last_tag_sufix);
        //        print "To remove keys:\n";
        //        print_r($to_remove_data_keys);

        $Stellar = $this->Stellar;

        $StellarAccount = $Stellar->requestAccount($id);
        $Transaction = new TransactionBuilder($StellarAccount);
        $Transaction->addMemo(Memo::text('BSN update'));
        $Transaction->setMaxOperationFee(10000);
        $operations = [];

        foreach ($to_remove_data_keys as $key) {
            $Operation = new ManageDataOperationBuilder($key, null);
            $operations[] = $Operation->build();
        }

        foreach ($to_add as $key => $data) {
            if (is_array($data)) {
                foreach ($data as $account_id) {
                    $last_tag_sufix[$key] = $last_tag_sufix[$key] ?? 0;
                    $last_tag_sufix[$key]++;
                    $key_name = $key . $last_tag_sufix[$key];
                    $Operation = new ManageDataOperationBuilder($key_name, $account_id);
                    $operations[] = $Operation->build();
                }
            } else {
                $Operation = new ManageDataOperationBuilder($key, $data);
                $operations[] = $Operation->build();
            }
        }

        $xdr = null;
        if ($operations) {
            $Transaction->addOperations($operations);
            $xdr = $Transaction->build()->toEnvelopeXdrBase64();
        } else {
            $filter_query = [];
            if ($single_contact) {
                $filter_query['id'] = $single_contact;
            }
            if ($single_tag) {
                $filter_query['tag'] = $single_tag;
            }

            SimpleRouter::response()->redirect(
                SimpleRouter::getUrl('editor', ['id' => $Account->getId()])
                . ($filter_query ? '?' . http_build_query($filter_query) : ''),
                302
            );
        }

        $sep_07 = 'web+stellar:tx?xdr=' . urlencode($xdr);
        $qr_svg = QRCode::svg($sep_07);

        // MMWB integration
        try {
            $HttpClient = new \GuzzleHttp\Client();
            $response = $HttpClient->post('https://eurmtl.me/remote/sep07/add', [
                'json' => ['uri' => $sep_07],
                'http_errors' => false
            ]);
            $response_body = (string) $response->getBody();
            $parsed_response = json_decode($response_body, true);
            $mmwb_url = $parsed_response['url'] ?? null;
        } catch (\Exception $e) {
            $mmwb_url = null;
        }

        $Template = $this->Twig->load('editor_result.twig');
        return $Template->render([
            'account' => [
                'id' => $Account->getId(),
                'short_id' => $Account->getShortId(),
                'display_name' => $Account->getDisplayName(),
            ],
            'operations_count' => count($operations),
            'xdr' => $xdr,
            'sep_07' => $sep_07,
            'mmwb_url' => $mmwb_url,
            'qr_svg' => $qr_svg,
        ]);
    }

    private function fetchAccountData(string $id): array
    {
        return $this->Stellar->requestAccount($id)->getData()->getData();
    }
}
