<?php

namespace Montelibero\BSN\Controllers;

use Montelibero\BSN\BSN;
use Pecee\SimpleRouter\SimpleRouter;
use Twig\Environment;

class TagsController
{
    private BSN $BSN;
    private Environment $Twig;

    public function __construct(BSN $BSN, Environment $Twig)
    {
        $this->BSN = $BSN;

        $this->Twig = $Twig;
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
            'tags' => $tags,
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
            'links' => $links,
        ]);
    }
}
