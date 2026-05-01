<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Montelibero\BSN\CurrentUser;
use Pecee\SimpleRouter\SimpleRouter;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class TagsController
{
    private BSN $BSN;
    private Environment $Twig;
    private Translator $Translator;
    private CurrentUser $CurrentUser;

    public function __construct(BSN $BSN, Environment $Twig, Translator $Translator, CurrentUser $CurrentUser)
    {
        $this->BSN = $BSN;
        $this->Twig = $Twig;
        $this->Translator = $Translator;
        $this->CurrentUser = $CurrentUser;
        $this->Twig->addGlobal('session', $_SESSION);
        $this->Twig->addGlobal('server', $_SERVER);
    }

    public function Tags(): ?string
    {
        $Source = null;
        if (isset($_GET['source']) && BSN::validateStellarAccountIdFormat($_GET['source'])) {
            $Source = $this->BSN->makeAccountById($_GET['source']);
        }
        $Target = null;
        if (isset($_GET['target']) && BSN::validateStellarAccountIdFormat($_GET['target'])) {
            $Target = $this->BSN->makeAccountById($_GET['target']);
        }
        $show_unknown_tags = $this->CurrentUser->getShowUnknownTags();

        $tags = [];
        foreach ($this->BSN->getLinks() as $Link) {
            if ($Source && $Link->getSourceAccount() !== $Source) {
                continue;
            }
            if ($Target && $Link->getTargetAccount() !== $Target) {
                continue;
            }

            $Tag = $Link->getTag();
            if (!array_key_exists($Tag->getName(), $tags)) {
                $tags[$Tag->getName()] = [
                    'name' => $Tag->getName(),
                    'is_single' => $Tag->isSingle(),
                    'out' => [],
                    'in' => [],
                ];
            }
            $tags[$Tag->getName()]['out'][] = $Link->getSourceAccount()->getId();
            $tags[$Tag->getName()]['in'][] = $Link->getTargetAccount()->getId();
        }

        foreach ($tags as $tag_name => $tagData) {
            $tags[$tag_name]['out'] = count(array_unique($tagData['out']));
            $tags[$tag_name]['in'] = count(array_unique($tagData['in']));
        }
        uasort($tags, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $tag_categories = [];
        foreach ($tags as $tag_name => $tag_data) {
            $Category = $this->BSN->getTagCategoryByTag($tag_name) ?? $this->BSN->getUnknownTagCategory();
            $category_id = $Category->getId();
            if (!array_key_exists($category_id, $tag_categories)) {
                $tag_categories[$category_id] = [
                    'id' => $category_id,
                    'name' => $Category->getName(),
                    'is_unknown' => $Category->isUnknown(),
                    'total' => 0,
                    'tags' => [],
                ];
            }

            $tag_categories[$category_id]['total'] += $tag_data['out'] + $tag_data['in'];
            $tag_categories[$category_id]['tags'][] = $tag_data;
        }

        $tag_categories = array_values($tag_categories);
        usort($tag_categories, function (array $a, array $b): int {
            if ($a['is_unknown'] !== $b['is_unknown']) {
                return $a['is_unknown'] ? 1 : -1;
            }

            return ($b['total'] <=> $a['total']) ?: strcasecmp($a['name'], $b['name']);
        });
        $unknown_tags_count = 0;
        if (!$show_unknown_tags) {
            $tag_categories = array_values(array_filter(
                $tag_categories,
                function (array $category) use (&$unknown_tags_count): bool {
                    if (!$category['is_unknown']) {
                        return true;
                    }

                    $unknown_tags_count = count($category['tags']);
                    return false;
                }
            ));
        } else {
            foreach ($tag_categories as $category) {
                if ($category['is_unknown']) {
                    $unknown_tags_count = count($category['tags']);
                    break;
                }
            }
        }

        $Template = $this->Twig->load('tags.twig');
        $filter_query = [];
        if ($Source) {
            $filter_query['source'] = $Source->getId();
        }
        if ($Target) {
            $filter_query['target'] = $Target->getId();
        }
        return $Template->render([
            'source' => $Source ? $Source->getId() : null,
            'target' => $Target ? $Target->getId() : null,
            'filter_query' => $filter_query ? http_build_query($filter_query) : '',
            'show_unknown_tags' => $show_unknown_tags,
            'unknown_tags_count' => $unknown_tags_count,
            'tags' => $tags,
            'tag_categories' => $tag_categories,
        ]);
    }

    public function Tag($name): ?string
    {
        $Source = null;
        if (isset($_GET['source']) && BSN::validateStellarAccountIdFormat($_GET['source'])) {
            $Source = $this->BSN->makeAccountById($_GET['source']);
        }
        $Target = null;
        if (isset($_GET['target']) && BSN::validateStellarAccountIdFormat($_GET['target'])) {
            $Target = $this->BSN->makeAccountById($_GET['target']);
        }

        $Tag = BSN::validateTagNameFormat($name)
            ? $this->BSN->findTagByName($name)
            : null;
        $tag_not_found = $Tag === null;

        if (!$Tag) {
            if (!BSN::validateTagNameFormat($name)) {
                SimpleRouter::response()->httpCode(404);
                return null;
            }

            $Tag = $this->BSN->makeTagByName($name);
            $Tag->isEditable(false);
            SimpleRouter::response()->httpCode(404);
        }

        if ($Tag->getName() !== $name) {
            SimpleRouter::response()->redirect(SimpleRouter::getUrl('tag', ['id' => $Tag->getName()]), 302);
            return null;
        }

        $links = [];
        $PairTag = $Tag->getPair();
        $is_pair = (bool) $PairTag;
        $is_pair_strong = $Tag->isPairStrong();

        foreach ($this->BSN->getLinks() as $Link) {
            if ($Link->getTag()->getName() !== $name) {
                continue;
            }
            if ($Source && $Link->getSourceAccount() !== $Source) {
                continue;
            }
            if ($Target && $Link->getTargetAccount() !== $Target) {
                continue;
            }
            $SourceAccount = $Link->getSourceAccount();
            $TargetAccount = $Link->getTargetAccount();
            $has_pair = $PairTag && in_array($SourceAccount, $TargetAccount->getOutcomeLinks($PairTag));

            $links[] = [
                'source_account' => $SourceAccount->jsonSerialize(),
                'target_account' => $TargetAccount->jsonSerialize(),
                'has_pair' => $has_pair,
                'pair_status_sort' => $has_pair ? 0 : ($is_pair_strong ? 2 : 1),
            ];
        }

        $Template = $this->Twig->load('tags_item.twig');
        return $Template->render([
            'tag' => [
                'name' => $Tag->getName(),
                'is_editable' => $Tag->isEditable(),
                'pair' => $is_pair,
                'pair_name' => $PairTag?->getName(),
                'pair_strong' => $is_pair_strong,
            ],
            'tag_not_found' => $tag_not_found,
            'known_tag' => $this->getKnownTagMetadata($Tag->getName()),
            'links' => $links,
        ]);
    }

    private function getKnownTagMetadata(string $tag_name): array
    {
        $known_tags = $this->loadKnownTagsList();
        $known_tag = $known_tags['links'][$tag_name] ?? null;
        $descriptions = $this->loadKnownTagDescriptions();
        $Category = $this->BSN->getTagCategoryByTag($tag_name);

        return [
            'is_known' => $known_tag !== null,
            'description' => $descriptions[$tag_name] ?? null,
            'category_id' => $Category?->getId(),
            'category_name' => $Category?->getName(),
            'is_standard' => (bool) ($known_tag['standard'] ?? false),
            'is_pair' => (bool) ($known_tag['pair'] ?? false),
            'is_pair_strong' => (bool) ($known_tag['strong_pair'] ?? false),
            'pair_name' => is_string($known_tag['pair'] ?? null) ? $known_tag['pair'] : null,
        ];
    }

    private function loadKnownTagsList(): array
    {
        static $known_tags = null;

        if ($known_tags === null) {
            $known_tags = json_decode(
                file_get_contents(dirname(__DIR__, 4) . '/known_tags/list.json'),
                true
            );
        }

        return $known_tags;
    }

    private function loadKnownTagDescriptions(): array
    {
        static $descriptions_by_locale = [];

        $locale = $this->Translator->getLocale();
        if (!array_key_exists($locale, $descriptions_by_locale)) {
            $path = dirname(__DIR__, 4) . '/known_tags/lang-' . $locale . '.json';
            if (!is_file($path)) {
                $path = dirname(__DIR__, 4) . '/known_tags/lang-en.json';
            }

            $parsed = json_decode(file_get_contents($path), true) ?? [];
            $descriptions_by_locale[$locale] = is_array($parsed['tags'] ?? null) ? $parsed['tags'] : $parsed;
        }

        return $descriptions_by_locale[$locale];
    }
}
