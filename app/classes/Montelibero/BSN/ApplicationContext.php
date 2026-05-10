<?php

namespace Montelibero\BSN;

use DI\Container;
use Pecee\Http\Request;
use Pecee\SimpleRouter\SimpleRouter;
use ReflectionProperty;
use Symfony\Component\Translation\Translator;

class ApplicationContext
{
    private static ?ReflectionProperty $RouterRequestProperty = null;
    private static ?ReflectionProperty $RouterResponseProperty = null;

    public function __construct(
        public readonly Container $Container,
        public readonly RequestSession $RequestSession,
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
        if (BotTrafficPolicy::shouldBlockCurrentRequest()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Forbidden\n";
            return;
        }

        try {
            $this->syncRequestContext();
            $this->refreshRouterRequest();
            SimpleRouter::start();
        } finally {
            $this->logServerErrorStatus();
            $this->RequestSession->endRequest();
        }
    }

    public function syncRequestContext(): void
    {
        $this->RequestSession->beginRequest();
        $this->SessionView->bind($_SESSION);
        $this->ServerView->bind($_SERVER);
        $this->RequestLocale->beginRequest();
        $this->Translator->setLocale($this->RequestLocale->getLocale());
        $this->CurrentUser->beginRequest();
        $this->CurrentContacts->beginRequest();
    }

    private function refreshRouterRequest(): void
    {
        $router = SimpleRouter::router();

        $RequestProperty = self::$RouterRequestProperty ??= new ReflectionProperty($router, 'request');
        $RequestProperty->setAccessible(true);
        $RequestProperty->setValue($router, new Request());

        $ResponseProperty = self::$RouterResponseProperty ??= new ReflectionProperty(SimpleRouter::class, 'response');
        $ResponseProperty->setAccessible(true);
        $ResponseProperty->setValue(null, null);
    }

    private function logServerErrorStatus(): void
    {
        $status_code = http_response_code();
        if (!is_int($status_code) || $status_code < 500) {
            return;
        }

        error_log(sprintf(
            'PHP request finished with HTTP %d: %s %s',
            $status_code,
            $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            $_SERVER['REQUEST_URI'] ?? ''
        ));
    }
}
