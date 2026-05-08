<?php

namespace Montelibero\BSN;

use DI\Container;
use Pecee\SimpleRouter\SimpleRouter;
use Symfony\Component\Translation\Translator;

class ApplicationContext
{
    public function __construct(
        public readonly Container $Container,
        public readonly RequestLocale $RequestLocale,
        public readonly Translator $Translator,
        public readonly CurrentUser $CurrentUser,
        public readonly CurrentContacts $CurrentContacts,
        public readonly RequestArrayView $SessionView,
        public readonly RequestArrayView $ServerView,
    ) {
    }

    public function handleRequest(): void
    {
        $this->syncRequestContext();
        SimpleRouter::start();
    }

    public function syncRequestContext(): void
    {
        $this->SessionView->bind($_SESSION);
        $this->ServerView->bind($_SERVER);
        $this->RequestLocale->beginRequest();
        $this->Translator->setLocale($this->RequestLocale->getLocale());
        $this->CurrentUser->beginRequest();
        $this->CurrentContacts->beginRequest();
    }
}
