<?php

namespace Montelibero\BSN;

class BotTrafficPolicy
{
    private const ROBOTS_TXT_PATH = __DIR__ . '/../../../robots.txt';

    private const PROBABLY_BOT_USER_AGENT_SUBSTRINGS = [
        'bot',
        'crawler',
        'facebookexternalhit',
        'slurp',
        'spider',
    ];

    private static ?array $robots_txt_groups = null;
    private static ?int $robots_txt_mtime = null;

    public static function probablyBot(?string $user_agent = null): bool
    {
        $user_agent = strtolower($user_agent ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($user_agent === '') {
            return false;
        }

        foreach (self::PROBABLY_BOT_USER_AGENT_SUBSTRINGS as $substring) {
            if (str_contains($user_agent, $substring)) {
                return true;
            }
        }

        return false;
    }

    public static function shouldBlockCurrentRequest(): bool
    {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (!self::probablyBot($user_agent)) {
            return false;
        }

        return !self::isAllowedByRobotsTxt(
            $_SERVER['REQUEST_URI'] ?? '/',
            $user_agent
        );
    }

    public static function isAllowedByRobotsTxt(string $request_uri, string $user_agent): bool
    {
        $groups = self::loadRobotsTxtGroups();
        if ($groups === []) {
            return true;
        }

        $rules = self::selectRulesForUserAgent($groups, $user_agent);
        if ($rules === []) {
            return true;
        }

        $path = self::normalizeRequestUri($request_uri);
        $matched_rule = null;
        $matched_length = -1;

        foreach ($rules as $rule) {
            if ($rule['pattern'] === '' || !self::robotsPatternMatches($rule['pattern'], $path)) {
                continue;
            }

            $length = strlen($rule['pattern']);
            if (
                $length > $matched_length
                || ($length === $matched_length && $rule['type'] === 'allow')
            ) {
                $matched_rule = $rule;
                $matched_length = $length;
            }
        }

        return $matched_rule === null || $matched_rule['type'] !== 'disallow';
    }

    private static function loadRobotsTxtGroups(): array
    {
        $mtime = is_file(self::ROBOTS_TXT_PATH) ? filemtime(self::ROBOTS_TXT_PATH) : null;
        if (self::$robots_txt_groups !== null && self::$robots_txt_mtime === $mtime) {
            return self::$robots_txt_groups;
        }

        self::$robots_txt_mtime = $mtime;
        if ($mtime === null) {
            return self::$robots_txt_groups = [];
        }

        return self::$robots_txt_groups = self::parseRobotsTxt((string) file_get_contents(self::ROBOTS_TXT_PATH));
    }

    private static function parseRobotsTxt(string $robots_txt): array
    {
        $groups = [];
        $current_agents = [];
        $current_rules = [];
        $rules_started = false;

        foreach (preg_split('/\R/', $robots_txt) as $line) {
            $line = trim(preg_replace('/\s*#.*$/', '', $line));
            if ($line === '') {
                self::appendRobotsGroup($groups, $current_agents, $current_rules);
                $current_agents = [];
                $current_rules = [];
                $rules_started = false;
                continue;
            }

            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
            $field = strtolower(trim($field));
            $value = trim($value);

            if ($field === 'user-agent') {
                if ($rules_started) {
                    self::appendRobotsGroup($groups, $current_agents, $current_rules);
                    $current_agents = [];
                    $current_rules = [];
                    $rules_started = false;
                }
                $current_agents[] = strtolower($value);
                continue;
            }

            if ($field === 'allow' || $field === 'disallow') {
                $rules_started = true;
                $current_rules[] = [
                    'type' => $field,
                    'pattern' => $value,
                ];
            }
        }

        self::appendRobotsGroup($groups, $current_agents, $current_rules);

        return $groups;
    }

    private static function appendRobotsGroup(array &$groups, array $agents, array $rules): void
    {
        if ($agents === []) {
            return;
        }

        $groups[] = [
            'agents' => array_values(array_unique($agents)),
            'rules' => $rules,
        ];
    }

    private static function selectRulesForUserAgent(array $groups, string $user_agent): array
    {
        $user_agent = strtolower($user_agent);
        $best_specificity = -1;
        $selected_rules = [];

        foreach ($groups as $group) {
            $group_specificity = -1;
            foreach ($group['agents'] as $agent) {
                if ($agent === '*') {
                    $group_specificity = max($group_specificity, 0);
                } elseif ($agent !== '' && str_contains($user_agent, $agent)) {
                    $group_specificity = max($group_specificity, strlen($agent));
                }
            }

            if ($group_specificity < 0) {
                continue;
            }

            if ($group_specificity > $best_specificity) {
                $best_specificity = $group_specificity;
                $selected_rules = $group['rules'];
            } elseif ($group_specificity === $best_specificity) {
                $selected_rules = array_merge($selected_rules, $group['rules']);
            }
        }

        return $selected_rules;
    }

    private static function normalizeRequestUri(string $request_uri): string
    {
        if ($request_uri === '') {
            return '/';
        }

        if (!str_starts_with($request_uri, '/')) {
            $path = parse_url($request_uri, PHP_URL_PATH) ?: '/';
            $query = parse_url($request_uri, PHP_URL_QUERY);

            return $path . ($query !== null && $query !== '' ? '?' . $query : '');
        }

        return $request_uri;
    }

    private static function robotsPatternMatches(string $pattern, string $path): bool
    {
        $end_anchored = str_ends_with($pattern, '$');
        if ($end_anchored) {
            $pattern = substr($pattern, 0, -1);
        }

        $regex = preg_quote($pattern, '~');
        $regex = str_replace('\*', '.*', $regex);
        $regex = '~^' . $regex . ($end_anchored ? '$' : '') . '~';

        return preg_match($regex, $path) === 1;
    }
}
