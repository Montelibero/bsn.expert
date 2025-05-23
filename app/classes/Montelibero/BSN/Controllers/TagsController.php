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

        $Tag = $this->BSN->getTag($name);
        if (!$Tag && BSN::validateTagNameFormat($name)) {
            $Tag = $this->BSN->makeTagByName($name);
        }

        if (!$Tag) {
            SimpleRouter::response()->httpCode(404);
            return null;
        }

        $links = [];

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
            $links[] = [
                'source_account' => $Link->getSourceAccount()->jsonSerialize(),
                'target_account' => $Link->getTargetAccount()->jsonSerialize(),
            ];
        }

        $Template = $this->Twig->load('tags_item.twig');
        return $Template->render([
            'tag' => [
                'name' => $Tag->getName(),
                'is_editable' => $Tag->isEditable(),
            ],
            'links' => $links,
        ]);
    }
}
