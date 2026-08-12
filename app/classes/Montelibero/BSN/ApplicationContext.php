<?php

namespace Montelibero\BSN;

use DI\Container;
use Montelibero\BSN\Controllers\ErrorController;
use Pecee\Http\Request;
use Pecee\SimpleRouter\Exceptions\NotFoundHttpException;
use Pecee\SimpleRouter\SimpleRouter;
use ReflectionProperty;
use Symfony\Component\Translation\Translator;

class ApplicationContext
{
    private static ?ReflectionProperty $RouterRequestProperty = null;
    private static ?ReflectionProperty $RouterResponseProperty = null;
    private bool $RouterRoutesLoaded = false;

    public function __construct(
        public readonly Container $Container,
        public readonly RequestSession $RequestSession,
        public readonly RequestLocale $RequestLocale,
        public readonly Translator $Translator,
        public readonly CurrentUser $CurrentUser,
        public readonly CurrentContacts $CurrentContacts,
        public readonly RequestArrayView $SessionView,
        public readonly RequestArrayView $ServerView,
        public readonly BSN $BSN,
        public readonly GristRuntimeData $GristRuntimeData,
        public readonly string $BsnJsonPath,
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
            $this->BSN->refreshFromJsonFileIfChanged($this->BsnJsonPath);
            $this->syncRequestContext();
            $this->refreshRouterRequest();
            $this->dispatchRouter();
        } finally {
            $this->logServerErrorStatus();
            $this->RequestSession->endRequest();
        }
    }

    public function syncRequestContext(): void
    {
        $this->GristRuntimeData->refreshMtlaMembersIfNeeded();
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

    private function dispatchRouter(): void
    {
        $Router = SimpleRouter::router();

        try {
            if (!$this->RouterRoutesLoaded) {
                foreach ($Router->getRoutes() as $Route) {
                    SimpleRouter::addDefaultNamespace($Route);
                }

                // Router::start() reloads and appends processed routes on every call.
                // A FrankenPHP worker keeps the static route map and only replaces request state.
                $Router->loadRoutes();
                $this->RouterRoutesLoaded = true;
            }

            echo $Router->routeRequest();
        } catch (NotFoundHttpException $Exception) {
            $this->renderRoutingError($Exception);
        }
    }

    private function renderRoutingError(NotFoundHttpException $Exception): void
    {
        $ErrorController = $this->Container->get(ErrorController::class);

        if ($Exception->getCode() === 404) {
            echo $ErrorController->Error404();
            return;
        }

        // simple-router reports a matching path with an unsupported method as 403.
        if ($Exception->getCode() === 403) {
            $allowed_methods = $this->getAllowedMethodsForCurrentRequest();
            $request_method = strtoupper(SimpleRouter::router()->getRequest()->getMethod());
            if ($allowed_methods !== [] && !in_array($request_method, $allowed_methods, true)) {
                echo $ErrorController->Error405($allowed_methods);
                return;
            }
        }

        throw $Exception;
    }

    /** @return list<string> */
    private function getAllowedMethodsForCurrentRequest(): array
    {
        $Router = SimpleRouter::router();
        $Request = $Router->getRequest();
        $url = $Request->getUrl()->getPath();
        $allowed_methods = [];

        foreach ($Router->getProcessedRoutes() as $Route) {
            if (!$Route->matchRoute($url, $Request)) {
                continue;
            }

            foreach ($Route->getRequestMethods() as $method) {
                $allowed_methods[strtoupper($method)] = true;
            }
        }

        return array_keys($allowed_methods);
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
