<?php

declare(strict_types=1);

use Montelibero\BSN\AssetVersions;
use Montelibero\BSN\TwigExtension;
use Montelibero\BSN\TwigPluralizeExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$twig_root = $root . '/twig';
$Translator = new Translator('en');
$Translator->addLoader('yaml', new YamlFileLoader());
$Translator->addResource('yaml', $root . '/i18n/messages.en.yaml', 'en');
$Translator->setFallbackLocales(['en']);

$Twig = new Environment(new FilesystemLoader($twig_root), [
    'cache' => false,
    'strict_variables' => false,
]);
$Twig->addExtension(new TwigExtension($Translator, new AssetVersions($root)));
$Twig->addExtension(new TranslationExtension($Translator));
$Twig->addExtension(new TwigPluralizeExtension($Translator));

$templates = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($twig_root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'twig') {
        continue;
    }

    $templates[] = str_replace(
        DIRECTORY_SEPARATOR,
        '/',
        substr($file->getPathname(), strlen($twig_root) + 1)
    );
}

sort($templates);
$errors = [];
foreach ($templates as $template) {
    try {
        $Twig->load($template);
    } catch (Throwable $Throwable) {
        $errors[] = sprintf('%s: %s', $template, $Throwable->getMessage());
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf("Twig syntax check passed for %d templates.\n", count($templates)));
